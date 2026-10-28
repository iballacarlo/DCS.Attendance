#pragma once

// Copy as device_config.h and keep the real values out of Git.
const char* WIFI_NAME = "YOUR_WIFI_NAME";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
const char* HARDWARE_API_KEY = "YOUR_RAILWAY_HARDWARE_API_KEY";

// Set this to true for one upload only if Serial Monitor says LittleFS cannot
// mount. Set it back to false immediately after the storage is initialized.
#define FORMAT_OFFLINE_STORAGE_ONCE false
// Add the actual trusted root CA PEM for your HTTPS API host. Never use setInsecure().
// Define this in device_config.h using a raw string containing the complete PEM:
// #define HARDWARE_ROOT_CA R"PEM(-----BEGIN CERTIFICATE-----
// ...actual CA certificate...
// -----END CERTIFICATE-----)PEM"
