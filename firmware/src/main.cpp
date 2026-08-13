/**
 * XTeInk — Afficheur Todo List (e-ink)
 *
 * Basé sur https://github.com/maddiedreese/xteink-tamagotchi
 * Liste de todos via HTTP (todos.php)
 *
 * Config locale : copie include/secrets.h.example → include/secrets.h
 */

#include <Arduino.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <WiFiClientSecure.h>
#include <ArduinoJson.h>
#include <SPI.h>

#include <GxEPD2_BW.h>
#include <Fonts/FreeMonoBold9pt7b.h>
#include <Fonts/FreeMonoBold12pt7b.h>
#include <Fonts/FreeMonoBold18pt7b.h>
#include <U8g2_for_Adafruit_GFX.h>
#include <InputManager.h>
#include "esp_sleep.h"

#include "secrets.h"

// ============================================================================
// CONFIGURATION
// ============================================================================

#ifndef API_TOKEN
#define API_TOKEN ""
#endif

#define MAX_TODOS 20
#define MAX_TODO_TEXT 96
#define TODOS_PER_PAGE 10
#define TODO_ROW_H_PREF 78
#define TODO_LIST_TOP 140
#define TODO_LIST_BOTTOM 740
#define TODO_TEXT_MAX_WIDTH 370

const uint64_t SLEEP_DURATION_US = 600ULL * 1000000ULL;  // 10 minutes
const unsigned long AUTO_SLEEP_DELAY = 300000;           // 5 minutes
const unsigned long MIN_REFRESH_INTERVAL = 2000;

// POWER (GPIO3) + ADC ladders (GPIO1/2) : réveil boutons depuis le deep sleep
const uint64_t BUTTON_WAKE_MASK =
    (1ULL << InputManager::POWER_BUTTON_PIN) |
    (1ULL << InputManager::BUTTON_ADC_PIN_1) |
    (1ULL << InputManager::BUTTON_ADC_PIN_2);

const char* NTP_SERVER = "pool.ntp.org";
const long GMT_OFFSET = 3600;       // UTC+1
const int DAYLIGHT_OFFSET = 3600;   // +1h été

// ============================================================================
// HARDWARE PINS (XTeInk X4)
// ============================================================================

#define EPD_SCLK 8
#define EPD_MOSI 10
#define EPD_CS 21
#define EPD_DC 4
#define EPD_RST 5
#define EPD_BUSY 6
#define BATTERY_PIN 0

GxEPD2_BW<GxEPD2_426_GDEQ0426T82, GxEPD2_426_GDEQ0426T82::HEIGHT> display(
    GxEPD2_426_GDEQ0426T82(EPD_CS, EPD_DC, EPD_RST, EPD_BUSY)
);

#define DISPLAY_WIDTH 480
#define DISPLAY_HEIGHT 800

U8G2_FOR_ADAFRUIT_GFX u8g2;
InputManager inputMgr;

// ============================================================================
// STATE
// ============================================================================

bool needsRedraw = true;
int batteryPercent = 100;
bool wifiConnected = false;
bool apiOk = false;
String lastFetchTime = "--:--";
unsigned long lastDisplayUpdate = 0;
unsigned long lastActivity = 0;
bool wokeFromTimer = false;

struct TodoItem {
    char text[MAX_TODO_TEXT];
    bool done;
};
TodoItem todos[MAX_TODOS];
int todoCount = 0;
int todoPending = 0;
int todoPage = 0;
bool todosValid = false;

// ============================================================================
// HELPERS
// ============================================================================

int readBatteryPercent() {
    int raw = analogRead(BATTERY_PIN);
    float voltage = raw * 2.0 * 3.3 / 4095.0;
    int percent = (int)((voltage - 3.0) / 1.2 * 100);
    return constrain(percent, 0, 100);
}

void applyApiAuth(HTTPClient& http) {
    if (API_TOKEN[0] != '\0') {
        http.addHeader("X-Api-Token", API_TOKEN);
    }
}

void showMessage(const char* line1, const char* line2 = nullptr, const char* line3 = nullptr) {
    display.setPartialWindow(0, 0, DISPLAY_WIDTH, DISPLAY_HEIGHT);
    display.firstPage();
    do {
        display.fillScreen(GxEPD_WHITE);
        display.setTextColor(GxEPD_BLACK);
        display.setFont(&FreeMonoBold18pt7b);
        display.setCursor(30, 350);
        display.print(line1);
        if (line2) {
            display.setCursor(30, 400);
            display.print(line2);
        }
        if (line3) {
            display.setFont(&FreeMonoBold12pt7b);
            display.setCursor(30, 460);
            display.print(line3);
        }
    } while (display.nextPage());
}

void waitPowerButtonRelease() {
    unsigned long start = millis();
    while (digitalRead(InputManager::POWER_BUTTON_PIN) == LOW) {
        if (millis() - start > 4000) break;
        delay(10);
    }
    inputMgr.update();
    inputMgr.update();
}

bool connectWifi() {
    Serial.print("Connexion WiFi...");
    WiFi.mode(WIFI_STA);
    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 30) {
        delay(500);
        Serial.print(".");
        attempts++;
    }

    wifiConnected = (WiFi.status() == WL_CONNECTED);
    if (wifiConnected) {
        Serial.println(" OK!");
        Serial.println(WiFi.localIP());
        configTime(GMT_OFFSET, DAYLIGHT_OFFSET, NTP_SERVER);
        delay(1500);
        struct tm timeinfo;
        if (getLocalTime(&timeinfo, 1000)) {
            char timeStr[6];
            strftime(timeStr, sizeof(timeStr), "%H:%M", &timeinfo);
            lastFetchTime = String(timeStr);
        }
    } else {
        Serial.println(" ECHEC");
    }
    return wifiConnected;
}

void goToSleep() {
    Serial.println("Mise en veille (deep sleep)...");

    int bottomY = DISPLAY_HEIGHT - 30;
    display.setPartialWindow(400, bottomY - 20, 70, 30);
    display.firstPage();
    do {
        display.fillScreen(GxEPD_WHITE);
        display.setTextColor(GxEPD_BLACK);
        display.setFont(&FreeMonoBold9pt7b);
        display.setCursor(405, bottomY);
        display.print("ZZ");
    } while (display.nextPage());

    display.hibernate();
    WiFi.disconnect(true);
    WiFi.mode(WIFI_OFF);
    Serial.flush();

    pinMode(InputManager::POWER_BUTTON_PIN, INPUT_PULLUP);
    pinMode(InputManager::BUTTON_ADC_PIN_1, INPUT);
    pinMode(InputManager::BUTTON_ADC_PIN_2, INPUT);

    esp_sleep_enable_timer_wakeup(SLEEP_DURATION_US);
    if (esp_deep_sleep_enable_gpio_wakeup(BUTTON_WAKE_MASK, ESP_GPIO_WAKEUP_GPIO_LOW) != ESP_OK) {
        esp_deep_sleep_enable_gpio_wakeup(1ULL << InputManager::POWER_BUTTON_PIN, ESP_GPIO_WAKEUP_GPIO_LOW);
    }
    esp_deep_sleep_start();
}

// ============================================================================
// HTTP FETCH
// ============================================================================

bool fetchTodos() {
    if (WiFi.status() != WL_CONNECTED) {
        wifiConnected = false;
        return false;
    }
    wifiConnected = true;

    Serial.println("Fetch todos depuis API...");

    WiFiClientSecure client;
    client.setInsecure();

    HTTPClient http;
    http.begin(client, API_TODOS);
    http.setTimeout(15000);
    applyApiAuth(http);

    int httpCode = http.GET();

    if (httpCode == HTTP_CODE_OK) {
        String payload = http.getString();
        Serial.println(payload);

        JsonDocument doc;
        DeserializationError error = deserializeJson(doc, payload);

        if (!error && doc["todos"].is<JsonArray>()) {
            JsonArray arr = doc["todos"].as<JsonArray>();
            todoCount = 0;
            todoPending = 0;

            for (JsonObject item : arr) {
                if (todoCount >= MAX_TODOS) break;
                const char* text = item["text"] | "";
                if (strlen(text) == 0) continue;

                String clean = String(text);
                if (clean.length() >= MAX_TODO_TEXT) {
                    unsigned int cut = MAX_TODO_TEXT - 1;
                    while (cut > 0 && (static_cast<unsigned char>(clean[cut]) & 0xC0) == 0x80) cut--;
                    clean = clean.substring(0, cut);
                }
                strlcpy(todos[todoCount].text, clean.c_str(), MAX_TODO_TEXT);
                todos[todoCount].done = item["done"] | false;
                if (!todos[todoCount].done) todoPending++;
                todoCount++;
            }

            for (int i = 0; i < todoCount - 1; i++) {
                for (int j = i + 1; j < todoCount; j++) {
                    if (todos[i].done && !todos[j].done) {
                        TodoItem tmp = todos[i];
                        todos[i] = todos[j];
                        todos[j] = tmp;
                    }
                }
            }

            todoPage = 0;
            todosValid = true;
            apiOk = true;

            struct tm timeinfo;
            if (getLocalTime(&timeinfo)) {
                char timeStr[6];
                strftime(timeStr, sizeof(timeStr), "%H:%M", &timeinfo);
                lastFetchTime = String(timeStr);
            }

            http.end();
            return true;
        }
        Serial.printf("JSON error: %s\n", error.c_str());
    } else {
        Serial.printf("HTTP error: %d\n", httpCode);
    }

    apiOk = false;
    todosValid = false;
    http.end();
    return false;
}

// ============================================================================
// DISPLAY
// ============================================================================

void displayTodos(bool force = false) {
    if (!needsRedraw && !force) return;

    unsigned long now = millis();
    if (!force && (now - lastDisplayUpdate) < MIN_REFRESH_INTERVAL) {
        return;
    }
    lastDisplayUpdate = now;

    Serial.println("Affichage todos...");

    int pageCount = todoCount > 0 ? ((todoCount + TODOS_PER_PAGE - 1) / TODOS_PER_PAGE) : 1;
    if (todoPage >= pageCount) todoPage = pageCount - 1;
    if (todoPage < 0) todoPage = 0;

    display.setPartialWindow(0, 0, DISPLAY_WIDTH, DISPLAY_HEIGHT);
    display.firstPage();

    do {
        display.fillScreen(GxEPD_WHITE);
        display.setTextColor(GxEPD_BLACK);

        display.setFont(&FreeMonoBold18pt7b);
        display.setCursor(30, 50);
        display.print("TODO");

        display.setFont(&FreeMonoBold9pt7b);
        display.setCursor(200, 45);
        display.print(todoPending);
        display.print("/");
        display.print(todoCount);
        if (pageCount > 1) {
            display.print("  p");
            display.print(todoPage + 1);
            display.print("/");
            display.print(pageCount);
        }

        display.drawFastHLine(30, 72, DISPLAY_WIDTH - 60, GxEPD_BLACK);

        if (!todosValid || todoCount == 0) {
            display.setFont(&FreeMonoBold12pt7b);
            display.setCursor(30, 220);
            display.print(todosValid ? "Empty list" : "API error");
            display.setFont(&FreeMonoBold9pt7b);
            display.setCursor(30, 270);
            display.print(todosValid ? "Add tasks on the" : "Check todos.php");
            display.setCursor(30, 300);
            display.print(todosValid ? "web UI" : "on the server");
        } else {
            int start = todoPage * TODOS_PER_PAGE;
            int onPage = todoCount - start;
            if (onPage > TODOS_PER_PAGE) onPage = TODOS_PER_PAGE;
            if (onPage < 0) onPage = 0;

            int available = TODO_LIST_BOTTOM - TODO_LIST_TOP;
            int rowH = TODO_ROW_H_PREF;
            if (onPage > 0 && onPage * TODO_ROW_H_PREF > available) {
                rowH = available / onPage;
            }

            int y = TODO_LIST_TOP + (rowH * 2) / 5;
            int boxSize = (rowH >= 70) ? 28 : ((rowH >= 58) ? 24 : 20);

            u8g2.setFont(u8g2_font_helvB18_tf);
            u8g2.setFontMode(1);
            u8g2.setForegroundColor(GxEPD_BLACK);

            for (int i = 0; i < onPage; i++) {
                int idx = start + i;

                int boxX = 30;
                int boxY = y - (boxSize * 3) / 4;
                display.drawRect(boxX, boxY, boxSize, boxSize, GxEPD_BLACK);
                if (todos[idx].done) {
                    display.drawLine(boxX + boxSize / 5, boxY + boxSize / 2,
                                     boxX + boxSize * 2 / 5, boxY + boxSize * 3 / 4, GxEPD_BLACK);
                    display.drawLine(boxX + boxSize * 2 / 5, boxY + boxSize * 3 / 4,
                                     boxX + boxSize * 4 / 5, boxY + boxSize / 5, GxEPD_BLACK);
                }

                String line = String(todos[idx].text);
                while (line.length() > 0 && u8g2.getUTF8Width(line.c_str()) > TODO_TEXT_MAX_WIDTH) {
                    unsigned int cut = line.length() - 1;
                    while (cut > 0 && (static_cast<unsigned char>(line[cut]) & 0xC0) == 0x80) cut--;
                    if (cut == 0) break;
                    line = line.substring(0, cut);
                }
                if (line != String(todos[idx].text)) {
                    line += ".";
                }

                u8g2.setCursor(70, y);
                u8g2.print(line);

                if (todos[idx].done) {
                    int w = u8g2.getUTF8Width(line.c_str());
                    display.drawFastHLine(70, y - 7, max(w, 40), GxEPD_BLACK);
                }

                y += rowH;
            }
        }

        display.setFont(&FreeMonoBold9pt7b);
        int bottomY = DISPLAY_HEIGHT - 30;

        display.setCursor(30, bottomY);
        display.print("Bat:");
        display.print(batteryPercent);
        display.print("%");

        display.setCursor(160, bottomY);
        display.print("WiFi:");
        display.print(wifiConnected ? "OK" : "--");

        display.setCursor(280, bottomY);
        display.print("Upd:");
        display.print(lastFetchTime);
        display.print(wokeFromTimer ? " A" : " M");

    } while (display.nextPage());

    needsRedraw = false;
    Serial.println("Todos displayed");
}

// ============================================================================
// SETUP / LOOP
// ============================================================================

void setup() {
    Serial.begin(115200);
    delay(500);
    Serial.println("\n\nXTeInk Todo List");
    Serial.println("================");

    esp_sleep_wakeup_cause_t wakeup_reason = esp_sleep_get_wakeup_cause();
    wokeFromTimer = (wakeup_reason == ESP_SLEEP_WAKEUP_TIMER);

    if (wokeFromTimer) {
        Serial.println("Reveil par timer");
    } else if (wakeup_reason == ESP_SLEEP_WAKEUP_GPIO) {
        Serial.println("Reveil par bouton");
    } else {
        Serial.println("Boot normal");
    }

    SPI.begin(EPD_SCLK, -1, EPD_MOSI, EPD_CS);
    display.init(115200);
    display.setRotation(3);
    display.setTextWrap(false);
    u8g2.begin(display);
    inputMgr.begin();

    if (wakeup_reason == ESP_SLEEP_WAKEUP_GPIO) {
        waitPowerButtonRelease();
    }

    bool fetched = false;
    if (connectWifi()) {
        fetched = fetchTodos();
        if (fetched) {
            Serial.println("Todos charges!");
        }
    }

    batteryPercent = readBatteryPercent();

    // Réveil timer : ne pas écraser l'écran si la synchro a échoué
    if (fetched || !wokeFromTimer) {
        needsRedraw = true;
        displayTodos(true);
    }

    if (wokeFromTimer) {
        Serial.println("Retour en veille...");
        delay(50);
        goToSleep();
    }

    lastActivity = millis();
    Serial.println("Mode interactif");
}

void loop() {
    inputMgr.update();
    bool buttonPressed = false;

    if (inputMgr.wasPressed(InputManager::BTN_BACK) && todoPage > 0) {
        todoPage = 0;
        needsRedraw = true;
        Serial.println("BACK: premiere page");
        buttonPressed = true;
    }

    int pageCount = todoCount > 0 ? ((todoCount + TODOS_PER_PAGE - 1) / TODOS_PER_PAGE) : 1;
    if (inputMgr.wasPressed(InputManager::BTN_LEFT) && todoPage > 0) {
        todoPage--;
        needsRedraw = true;
        Serial.printf("LEFT: page %d\n", todoPage);
        buttonPressed = true;
    }
    if (inputMgr.wasPressed(InputManager::BTN_RIGHT) && todoPage < pageCount - 1) {
        todoPage++;
        needsRedraw = true;
        Serial.printf("RIGHT: page %d\n", todoPage);
        buttonPressed = true;
    }

    if (inputMgr.wasPressed(InputManager::BTN_CONFIRM)) {
        Serial.println("CONFIRM: fetch manuel");
        showMessage("Loading...", "", "");
        fetchTodos();
        displayTodos(true);
        buttonPressed = true;
    }

    if (inputMgr.wasPressed(InputManager::BTN_POWER)) {
        Serial.println("POWER: veille immediate");
        goToSleep();
    }

    if (buttonPressed) {
        lastActivity = millis();
    }

    if (millis() - lastActivity > AUTO_SLEEP_DELAY) {
        Serial.println("Auto-sleep (inactivite)");
        goToSleep();
    }

    displayTodos();
    delay(50);
}
