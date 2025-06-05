FROM node:18-slim

# Install dependencies for Chromium
RUN apt-get update && apt-get install -y \
    chromium \
    fonts-liberation \
    libasound2 \
    libatk-bridge2.0-0 \
    libatk1.0-0 \
    libatspi2.0-0 \
    libcups2 \
    libdbus-1-3 \
    libdrm2 \
    libgtk-3-0 \
    libnspr4 \
    libnss3 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxkbcommon0 \
    libxrandr2 \
    xdg-utils \
    libu2f-udev \
    libvulkan1 \
    curl \
    ca-certificates \
    --no-install-recommends && \
    apt-get clean && rm -rf /var/lib/apt/lists/*

# Set environment variable to skip Puppeteer's Chromium install
ENV PUPPETEER_SKIP_CHROMIUM_DOWNLOAD=true
ENV PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium

# Create and switch to app directory
WORKDIR /usr/src/app

# Copy package.json and install dependencies
COPY docker/puppeteer/package.json ./
RUN npm install

# Copy your server script
COPY docker/puppeteer/server.js ./

# Add non-root user for security
RUN groupadd -r puppeteer && useradd -r -g puppeteer -G audio,video puppeteer && \
    mkdir -p /home/puppeteer/Downloads && \
    chown -R puppeteer:puppeteer /home/puppeteer && \
    chown -R puppeteer:puppeteer /usr/src/app

USER puppeteer

# Expose Puppeteer server port
EXPOSE 3000

# Start your server
CMD ["node", "server.js"]
