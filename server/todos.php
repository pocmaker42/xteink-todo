<?php
/**
 * XTeInk TODO List - API + stockage JSON
 *
 * GET  → liste des todos (pour le device et l'UI)
 * POST → actions CRUD (add, toggle, update, delete, clear_done, reorder, set_today)
 */

if (is_readable(__DIR__ . '/bootstrap.php')) {
    require_once __DIR__ . '/bootstrap.php';
} elseif (!function_exists('xteink_config')) {
    function xteink_config(): array {
        $defaults = ['base_url' => '', 'api_token' => ''];
        $path = __DIR__ . '/config.php';
        if (!is_readable($path)) {
            return $defaults;
        }
        $loaded = require $path;
        return array_merge($defaults, is_array($loaded) ? $loaded : []);
    }
    function xteink_request_token(): string {
        $header = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
        return $header !== '' ? (string)$header : (string)($_GET['token'] ?? '');
    }
    function xteink_require_auth(): void {
        $expected = (string)(xteink_config()['api_token'] ?? '');
        if ($expected === '' || hash_equals($expected, xteink_request_token())) {
            return;
        }
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Token');
header('Cache-Control: no-cache, no-store, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

xteink_require_auth();

const TODOS_DIR = __DIR__ . '/data';
const TODOS_FILE = TODOS_DIR . '/todos.json';
const TODOS_FILE_LEGACY = __DIR__ . '/todos.json';
const MAX_TEXT_LEN = 120;
const MAX_TODOS = 50;

function respond(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function emptyTodos(): array {
    return ['todos' => [], 'updated_at' => null, 'today_count' => 0];
}

function clampTodayCount(array $data): int {
    $max = count($data['todos'] ?? []);
    if (!isset($data['today_count'])) {
        return $max;
    }
    return max(0, min((int)$data['today_count'], $max));
}

function withTodayCount(array $data): array {
    $data['today_count'] = clampTodayCount($data);
    return $data;
}

function todosPublic(array $data, array $extra = []): array {
    $data = withTodayCount($data);
    $pending = 0;
    foreach ($data['todos'] as $todo) {
        if (empty($todo['done'])) {
            $pending++;
        }
    }
    return array_merge([
        'todos' => $data['todos'],
        'total' => count($data['todos']),
        'pending' => $pending,
        'today_count' => $data['today_count'],
        'updated_at' => $data['updated_at'] ?? null,
    ], $extra);
}

function respondTodos(array $data): void {
    respond(todosPublic($data, ['ok' => true]));
}

function ensureTodosStorage(): void {
    if (!is_dir(TODOS_DIR) && !@mkdir(TODOS_DIR, 0755, true) && !is_dir(TODOS_DIR)) {
        respond(['error' => 'Cannot create data/ folder'], 500);
    }

    // Migration automatique depuis l'ancien emplacement
    if (!file_exists(TODOS_FILE) && file_exists(TODOS_FILE_LEGACY)) {
        @rename(TODOS_FILE_LEGACY, TODOS_FILE);
    }
}

function loadTodos(): array {
    ensureTodosStorage();

    if (!file_exists(TODOS_FILE)) {
        $empty = emptyTodos();
        @file_put_contents(TODOS_FILE, json_encode($empty, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n", LOCK_EX);
        return $empty;
    }
    $raw = file_get_contents(TODOS_FILE);
    $data = json_decode($raw ?: '', true);
    if (!is_array($data) || !isset($data['todos']) || !is_array($data['todos'])) {
        return emptyTodos();
    }
    return $data;
}

function saveTodos(array $data): void {
    ensureTodosStorage();
    $data = withTodayCount($data);
    $data['updated_at'] = date('c');
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents(TODOS_FILE, $json . "\n", LOCK_EX) === false) {
        respond([
            'error' => 'Cannot write data/todos.json — check data/ folder permissions',
        ], 500);
    }
}

function findTodoIndex(array $todos, string $id): int {
    foreach ($todos as $i => $todo) {
        if (($todo['id'] ?? '') === $id) {
            return $i;
        }
    }
    return -1;
}

function sanitizeText(string $text): string {
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($text, 0, MAX_TEXT_LEN);
    }
    return substr($text, 0, MAX_TEXT_LEN);
}

function newId(): string {
    return bin2hex(random_bytes(6));
}

$data = withTodayCount(loadTodos());

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(todosPublic($data));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

switch ($action) {
    case 'add':
        $text = sanitizeText((string)($input['text'] ?? ''));
        if ($text === '') {
            respond(['error' => 'Text required'], 400);
        }
        if (count($data['todos']) >= MAX_TODOS) {
            respond(['error' => 'Limit of ' . MAX_TODOS . ' todos reached'], 400);
        }
        array_unshift($data['todos'], [
            'id' => newId(),
            'text' => $text,
            'done' => false,
            'created_at' => date('c'),
        ]);
        $data['today_count'] = clampTodayCount($data) + 1;
        saveTodos($data);
        respondTodos($data);

    case 'toggle':
        $id = (string)($input['id'] ?? '');
        $idx = findTodoIndex($data['todos'], $id);
        if ($idx < 0) {
            respond(['error' => 'Todo not found'], 404);
        }
        $data['todos'][$idx]['done'] = empty($data['todos'][$idx]['done']);
        saveTodos($data);
        respondTodos($data);

    case 'update':
        $id = (string)($input['id'] ?? '');
        $idx = findTodoIndex($data['todos'], $id);
        if ($idx < 0) {
            respond(['error' => 'Todo not found'], 404);
        }
        $text = sanitizeText((string)($input['text'] ?? ''));
        if ($text === '') {
            respond(['error' => 'Text required'], 400);
        }
        $data['todos'][$idx]['text'] = $text;
        saveTodos($data);
        respondTodos($data);

    case 'delete':
        $id = (string)($input['id'] ?? '');
        $idx = findTodoIndex($data['todos'], $id);
        if ($idx < 0) {
            respond(['error' => 'Todo not found'], 404);
        }
        $today = clampTodayCount($data);
        array_splice($data['todos'], $idx, 1);
        $data['today_count'] = $idx < $today ? $today - 1 : $today;
        saveTodos($data);
        respondTodos($data);

    case 'clear_done':
        $today = clampTodayCount($data);
        $keptToday = 0;
        foreach (array_slice($data['todos'], 0, $today) as $todo) {
            if (empty($todo['done'])) {
                $keptToday++;
            }
        }
        $data['todos'] = array_values(array_filter(
            $data['todos'],
            static function ($todo) {
                return empty($todo['done']);
            }
        ));
        $data['today_count'] = $keptToday;
        saveTodos($data);
        respondTodos($data);

    case 'reorder':
        $ids = $input['ids'] ?? null;
        if (!is_array($ids)) {
            respond(['error' => 'ids required'], 400);
        }
        $byId = [];
        foreach ($data['todos'] as $todo) {
            $byId[$todo['id']] = $todo;
        }
        $reordered = [];
        foreach ($ids as $id) {
            $id = (string)$id;
            if (isset($byId[$id])) {
                $reordered[] = $byId[$id];
                unset($byId[$id]);
            }
        }
        // Conserver les todos non mentionnés
        foreach ($byId as $todo) {
            $reordered[] = $todo;
        }
        $data['todos'] = $reordered;
        saveTodos($data);
        respondTodos($data);

    case 'set_today':
        $data['today_count'] = max(0, min((int)($input['count'] ?? 0), count($data['todos'])));
        saveTodos($data);
        respondTodos($data);

    default:
        respond(['error' => 'Unknown action'], 400);
}
