#pragma once

// Copy as device_config.h and keep the real values out of Git.
const char* WIFI_NAME = "YOUR_WIFI_NAME";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
// Set true only when the router reserves this address for the terminal.
#define USE_STATIC_IP false
#define STATIC_IP_ADDRESS 192,168,1,13
#define STATIC_GATEWAY_ADDRESS 192,168,1,1
#define STATIC_SUBNET_ADDRESS 255,255,255,0
#define STATIC_DNS_ADDRESS 192,168,1,1
const char* HARDWARE_API_KEY = "YOUR_RAILWAY_HARDWARE_API_KEY";
// Copy the Terminal ID generated in Settings > Rooms & Devices exactly as shown.
// It identifies this physical terminal to the server; it is not the room name.
// Keep this same ID across firmware/OTA updates and room reassignment.
const char* DEVICE_CODE = "TERM-EXAMPLE01";

// Optional startup room label, matching this terminal's actual room assignment.
// Cached/server room updates override this display-only fallback.
#define DEVICE_ROOM_LABEL ""

// First upload is through USB. Later, select this network port in Arduino IDE.
// Use a unique hostname per terminal and a private password (required for OTA).
#define OTA_HOSTNAME "dcs-attendance-tft"
#define OTA_PASSWORD "CHANGE_ME_BEFORE_UPLOAD"

// Set this to true for one upload only if Serial Monitor says LittleFS cannot
// mount. Set it back to false immediately after the storage is initialized.
#define FORMAT_OFFLINE_STORAGE_ONCE false

// railway_root_ca.h supplies the verified public CA for the default Railway host.
// To override it for a different HTTPS host, add its trusted root CA here. Never use setInsecure().
// Define this in device_config.h using a raw string containing the complete PEM:
// static const char CUSTOM_ROOT_CA[] = R"PEM(-----BEGIN CERTIFICATE-----
// ...actual CA certificate...
// -----END CERTIFICATE-----)PEM";
// #define HARDWARE_ROOT_CA CUSTOM_ROOT_CA
