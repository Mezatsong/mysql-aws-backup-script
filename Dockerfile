# Use an official PHP image as a parent image
FROM php:8.2-cli

# Install system dependencies required for the script and cron
# - default-mysql-client provides mysqldump
# - cron is the scheduler
# - gzip is for compressing the database dump
# - unzip and git are needed by composer
RUN apt-get update && apt-get install -y \
    unzip \
    git \
    cron \
    gzip \
    default-mysql-client \
    && rm -rf /var/lib/apt/lists/*

# Install required PHP extensions
RUN docker-php-ext-install pdo_mysql

# Install Composer (PHP package manager)
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set the working directory in the container
WORKDIR /app

# Copy composer files and install dependencies. This is done in two steps
# to leverage Docker's layer caching.
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist

# Copy the rest of the application files into the container
COPY . .

# Add the cron job to run the backup script.
# This example runs it daily at 2:00 AM.
# The output is logged to /var/log/cron.log
RUN echo "0 2 * * * php /app/backup.php >> /var/log/cron.log 2>&1" > /etc/cron.d/backup-cron

# Give execution rights on the cron job
RUN chmod 0644 /etc/cron.d/backup-cron
RUN crontab /etc/cron.d/backup-cron

# Create the log file to be able to run tail
RUN touch /var/log/cron.log

# Run cron in the foreground to keep the container running
CMD ["cron", "-f"]
