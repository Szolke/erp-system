FROM node:20-alpine

# Rendszer-Chromium + a Playwright headless futtatásához szükséges shared libek.
# Alpine (musl libc) miatt a Playwright saját letöltésű Chromiuma NEM kompatibilis,
# ezért az apk-s rendszer-Chromiumot kell használni (PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD).
RUN apk add --no-cache \
    chromium \
    nss \
    freetype \
    harfbuzz \
    ca-certificates \
    ttf-freefont \
    font-noto

ENV PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 \
    PLAYWRIGHT_CHROMIUM_EXECUTABLE_PATH=/usr/bin/chromium-browser
