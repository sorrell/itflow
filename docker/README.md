# ITFlow Docker Setup

This directory contains Docker configuration for running ITFlow in a containerized environment.

## Files in this directory

- `docker-compose.yml` - Docker Compose configuration for ITFlow and MariaDB database
- `.env` - Environment variables for Docker Compose and ITFlow
- `.env.example` - Example environment file with default values
- `auto_setup.php` - Script to automate the ITFlow installation process 
- `entrypoint.sh` - Container initialization script

## Getting Started

1. Copy the `.env.example` file to `.env` and modify as needed:
   ```
   cp .env.example .env
   ```

2. Start the ITFlow containers:
   ```
   docker-compose up -d
   ```

3. Access ITFlow at http://localhost:8088

## Auto Setup

The container automatically runs the setup process using values from your `.env` file.

## File Structure

The Docker setup mounts the ITFlow application code from the parent directory into the container. 