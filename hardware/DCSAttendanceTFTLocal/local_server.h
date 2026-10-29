#pragma once

// Computer LAN IPv4 address; do not use localhost, 127.0.0.1, or a Railway URL.
// If DHCP changes the computer address, update this value and re-upload.
#ifndef LOCAL_SERVER_HOST
#define LOCAL_SERVER_HOST "192.168.1.13"
#endif
#ifndef LOCAL_SERVER_PORT
#define LOCAL_SERVER_PORT 8000
#endif

static_assert(LOCAL_SERVER_PORT > 0 && LOCAL_SERVER_PORT <= 65535,
              "LOCAL_SERVER_PORT must be between 1 and 65535.");

// Restrict the plaintext test transport to private LAN IPv4 destinations.
inline bool localServerSettingsValid() {
  IPAddress address;
  if (!address.fromString(LOCAL_SERVER_HOST)) return false;
  return address[0] == 10 ||
         (address[0] == 172 && address[1] >= 16 && address[1] <= 31) ||
         (address[0] == 192 && address[1] == 168);
}
