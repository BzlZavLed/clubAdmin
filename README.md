# Club Portal

Club Portal is a web application built using the Laravel framework. It provides a robust foundation for building modern web applications with features like authentication, routing, and database management.

## Features

- Built with Laravel 12.x
- Tailwind CSS for styling
- Inertia.js for seamless frontend-backend integration
- Sanctum for API authentication
- PHPWord for document generation
- Ziggy for JavaScript routing

## Requirements

- PHP 8.2 or higher
- Composer
- Node.js and npm

## Installation

1. Clone the repository:

    ```bash
    git clone <repository-url>
    cd club-portal
    ```

2. Install PHP dependencies:

    ```bash
    composer install
    ```

3. Install JavaScript dependencies:

    ```bash
    npm install
    ```

4. Copy the `.env.example` file to `.env` and configure your environment variables:

    ```bash
    cp .env.example .env
    ```

5. Generate the application key:

    ```bash
    php artisan key:generate
    ```

6. Run database migrations:

    ```bash
    php artisan migrate
    ```

## Development

To start the development server:

```bash
php artisan serve
npm run dev