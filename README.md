# SIS-KYRO-REFACTOR

Refactorización del sistema ERP Bitel. Laravel 11 API + React 18 SPA + MySQL + Redis.

## Stack

- **Backend**: Laravel 11 + PHP 8.2 + Sanctum + Greenter (SUNAT)
- **Frontend**: React 18 + Vite + TypeScript + TanStack Query + shadcn/ui
- **BD**: MySQL (host externo) — base de datos `migracion`
- **Cache/Queue**: Redis 7

## Inicio rápido

### Backend
```bash
cd backend
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

### Frontend
```bash
cd frontend
cp .env.example .env.local
npm install
npm run dev
```

### Docker (Redis + React dev)
```bash
docker-compose up -d
```

## Variables de entorno

Backend `.env` — ver `.env.example`  
Frontend `.env.local` — ver `.env.example`
