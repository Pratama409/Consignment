# Consignment Control Center

Fondasi aplikasi operasional consignment berbasis Laravel, Inertia, dan Vue.
Role yang tersedia adalah **Admin**, **Warehouse**, dan **Vendor**. Admin
mengelola user, jadwal shift, saldo awal, approval koreksi, dan reversal;
Warehouse menjalankan pekerjaan gudang serta membuat draft koreksi; Vendor
hanya melihat data barang yang terhubung ke Vendor akunnya.

## Menjalankan secara lokal

```powershell
composer install
npm install
php artisan migrate --seed
php artisan serve
npm run dev
```

Pembuatan Admin dilakukan secara interaktif agar password tidak muncul dalam
command history:

```powershell
php artisan app:create-admin
```

Data demo (Vendor, barang, saldo awal, refill parsial, dan satu Data OUT dengan
Nomor WO serta Backcharge) tersedia lewat `DemoDataSeeder`. Seeder ini memakai
action aplikasi yang sama, menolak berjalan di production, dan otomatis dipanggil
oleh `php artisan migrate --seed` hanya pada `APP_ENV=local`. Untuk menjalankannya
secara eksplisit: `php artisan db:seed --class=DemoDataSeeder`. Akun demo
(`admin@demo.test`, `warehouse@demo.test`, `vendor@demo.test`) memakai password
`Demo-password-123`; jangan dipakai di luar lingkungan lokal.

Jangan menjalankan migration pada database utama atau production tanpa backup,
maintenance window, dan instruksi eksplisit. Pengujian MySQL harus memakai
database `consignment_testing`; SQLite `:memory:` dipakai oleh test default.

## Fondasi inventory

- Shift default WITA: DAY 06:00–18:00 dan NIGHT 18:00–06:00. Perubahan hanya
  berlaku ke depan dan tidak mengelompokkan ulang instance historis.
- Opening balance menggunakan alur upload XLSX → preview → aktivasi Admin.
  Preview tidak mengubah stok.
- `stock_ledger_entries` adalah sumber kebenaran immutable. Saldo dan lot adalah
  proyeksi terkunci dari ledger, bukan data yang diedit bebas.
- Posting memakai idempotency key dan transaksi database. Quantity menggunakan
  empat desimal dengan rentang lintas database `0.0001–9999999999.9999`.
- Koreksi dibuat sebagai draft, direview Admin, lalu diposting. Reversal membuat
  transaksi lawan dan tidak menghapus histori asli.

## Pemeriksaan

```powershell
php artisan test --compact
php vendor/bin/pint --test
npm run build
npm run test:browser
php vendor/phpunit/phpunit/phpunit -c phpunit.mysql.xml
```

Perintah MySQL terakhir hanya dijalankan setelah koneksi test terisolasi telah
diverifikasi. Jangan arahkan konfigurasi test ke database aplikasi.

Panduan website, alur Master Data, import/export Excel, dan cara menjalankan:
[Master Data UI](docs/master-data-ui.md). Aturan skema dan relasi:
[Database Master Data](docs/master-data-database.md). Panduan visual:
[DESIGN.md](DESIGN.md).

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
