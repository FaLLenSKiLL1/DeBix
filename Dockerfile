FROM php:8.3-cli-alpine

# Install dependencies for zip extension
RUN apk add --no-cache libzip-dev \
    && docker-php-ext-install zip

# Set working directory
WORKDIR /app

# Copy DeBix.php to the container
COPY DeBix.php /app/

# Create entrypoint script to handle arguments
RUN echo '#!/bin/sh' > /entrypoint.sh \
    && echo 'php /app/DeBix.php "$@"' >> /entrypoint.sh \
    && chmod +x /entrypoint.sh

# Target project should be mounted at /src
VOLUME /src

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/src"]
