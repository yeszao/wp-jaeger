# Start from the official Composer image
FROM composer:latest

# Set the working directory
WORKDIR /app

# Configure Git to recognize /app as a safe directory
RUN git config --global --add safe.directory /app
