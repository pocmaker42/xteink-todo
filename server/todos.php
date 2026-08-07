<?php
/**
 * XTeInk TODO List - API + stockage JSON
 *
 * GET  → liste des todos (pour le device et l'UI)
 * POST → actions CRUD (add, toggle, update, delete, clear_done, reorder)
 */

require_once __DIR__ . '/bootstrap.php';

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
    return ['todos' => [], 'updated_at' => null];
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

$data = loadTodos();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pending = 0;
    foreach ($data['todos'] as $todo) {
        if (empty($todo['done'])) {
            $pending++;
        }
    }
    respond([
        'todos' => $data['todos'],
        'total' => count($data['todos']),
        'pending' => $pending,
        'updated_at' => $data['updated_at'],
    ]);
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
        $data['todos'][] = [
            'id' => newId(),
            'text' => $text,
            'done' => false,
            'created_at' => date('c'),
        ];
        saveTodos($data);
        respond(['ok' => true, 'todos' => $data['todos'], 'updated_at' => $data['updated_at']]);

    case 'toggle':
        $id = (string)($input['id'] ?? '');
        $idx = findTodoIndex($data['todos'], $id);
        if ($idx < 0) {
            respond(['error' => 'Todo not found'], 404);
        }
        $data['todos'][$idx]['done'] = empty($data['todos'][$idx]['done']);
        saveTodos($data);
        respond(['ok' => true, 'todos' => $data['todos'], 'updated_at' => $data['updated_at']]);

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
        respond(['ok' => true, 'todos' => $data['todos'], 'updated_at' => $data['updated_at']]);

    case 'delete':
        $id = (string)($input['id'] ?? '');
        $idx = findTodoIndex($data['todos'], $id);
        if ($idx < 0) {
            respond(['error' => 'Todo not found'], 404);
        }
        array_splice($data['todos'], $idx, 1);
        saveTodos($data);
        respond(['ok' => true, 'todos' => $data['todos'], 'updated_at' => $data['updated_at']]);

    case 'clear_done':
        $data['todos'] = array_values(array_filter(
            $data['todos'],
            static fn($todo) => empty($todo['done'])
        ));
        saveTodos($data);
        respond(['ok' => true, 'todos' => $data['todos'], 'updated_at' => $data['updated_at']]);

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
        respond(['ok' => true, 'todos' => $data['todos'], 'updated_at' => $data['updated_at']]);

    default:
        respond(['error' => 'Unknown action'], 400);
}
