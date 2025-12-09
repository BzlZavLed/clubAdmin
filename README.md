# Club Portal

A web-based Club Management System built with **Laravel**, **Vue.js**, and **Tailwind CSS**. It provides tools to manage church clubs such as Adventurers, Pathfinders, and Master Guides.

## Features

* User authentication and roles (director, staff, parent, treasurer, secretary, adviser, etc.)
* Church and Club registration and management
* Member and Staff management
* Staff-to-member relationship tracking
* Parent registration and child application forms
* Service and event tracking for club members
* Report generation and status tracking
* File and image uploads (e.g., profile pictures, forms)

## Tech Stack

* **Backend**: Laravel (PHP)
* **Frontend**: Vue.js
* **Styling**: Tailwind CSS
* **Build Tools**: Vite
* **Database**: MySQL

## Installation

### Requirements

* PHP >= 8.2
* Composer
* Node.js >= 18
* MySQL or MariaDB

### Setup

```bash
git clone <repository-url>
cd club-portal
cp .env.example .env
composer install
php artisan key:generate
npm install
npm run dev
php artisan migrate --seed
```

### Running

```bash
php artisan serve
```

## Folder Structure Highlights

* `app/` – Application logic (Models, Controllers)
* `resources/views` – Blade templates
* `routes/web.php` – Web routes
* `database/` – Migrations and seeders
* `public/` – Public assets and entry point

## Roles (selected)

- `parent`:
  - Redirect after login: `/parent/apply` (via `RedirectIfAuthenticated::redirectPath`)
  - Guest self-registration: `GET/POST /register-parent`, helper `GET /churches/{church}/clubs`
  - Authenticated (middleware: `auth`, `verified`, `auth.parent`):
    - `GET /parent/apply` (`parent.apply`)
    - `POST /parent/apply` (`parent.apply.submit`)
    - `GET /parent/children` (`parent-links.index.parent`)

## Utilities

- Seed default pay-to options (fills `pay_to_options` for all clubs, or one via `--club_id`):
  ```bash
  php artisan payto:seed
  php artisan payto:seed --club_id=1
  ```

## Environment Variables

Configure your `.env` file:

```env
APP_NAME="Club Portal"
APP_URL=http://localhost
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=club_portal
DB_USERNAME=root
DB_PASSWORD=
```

## Contributing

Pull requests are welcome. For major changes, please open an issue first.

## License

[MIT](https://choosealicense.com/licenses/mit/)
