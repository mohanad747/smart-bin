// =====================================================================
// Copy this file to secrets.h (same folder) and fill in your values.
// secrets.h is NOT in the repo because it holds the Wi-Fi password and token.
// =====================================================================
#pragma once

// Wi-Fi (phone hotspot or router). The ESP32 only supports 2.4 GHz.
#define WIFI_SSID     "your-hotspot-name"
#define WIFI_PASS     "your-hotspot-password"

// Exact ingest URL from the dashboard's Settings page ("ESP32 connection").
// The laptop IP can change when the hotspot changes.
#define SERVER_URL    "http://192.168.1.10/smart_bin/api/ingest.php"

// Must match the bin's row on the Settings page
#define BIN_ID        "BIN-01"
#define DEVICE_TOKEN  "paste-the-device-token-here"
