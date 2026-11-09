#pragma once

// Copy to device_config.h. Keep real values private; this filename is ignored.
const char* WIFI_NAME = "YOUR_WIFI_NAME";
const char* WIFI_PASSWORD = "YOUR_WIFI_PASSWORD";
// Match HARDWARE_API_KEY in the LOCAL Laravel .env, not the Railway variable.
const char* HARDWARE_API_KEY = "YOUR_LOCAL_HARDWARE_API_KEY";
// Copy the Terminal ID generated in Settings > Rooms & Devices exactly as shown.
const char* DEVICE_CODE = "TERM-EXAMPLE01";
// Set the computer IP and port in local_server.h. No TLS certificate is needed.
