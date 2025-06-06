FROM sail-8.4/app

# Install supervisor
RUN apt-get update && apt-get install -y supervisor \
    && mkdir -p /var/log/supervisor \
    && rm -rf /var/lib/apt/lists/*

# Copy supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Create entrypoint script
RUN echo '#!/bin/bash' > /usr/local/bin/supervisor-entrypoint.sh \
    && echo 'if [ "$WWWUSER" ]; then' >> /usr/local/bin/supervisor-entrypoint.sh \
    && echo '    usermod -u $WWWUSER sail' >> /usr/local/bin/supervisor-entrypoint.sh \
    && echo 'fi' >> /usr/local/bin/supervisor-entrypoint.sh \
    && echo 'if [ "$WWWGROUP" ]; then' >> /usr/local/bin/supervisor-entrypoint.sh \
    && echo '    groupmod -g $WWWGROUP sail' >> /usr/local/bin/supervisor-entrypoint.sh \
    && echo 'fi' >> /usr/local/bin/supervisor-entrypoint.sh \
    && echo 'exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf' >> /usr/local/bin/supervisor-entrypoint.sh \
    && chmod +x /usr/local/bin/supervisor-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/supervisor-entrypoint.sh"]
