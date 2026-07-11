# Headers de seguridad HTTP

El middleware global `SecurityHeaders` agrega a todas las respuestas de Laravel:

- `X-Content-Type-Options: nosniff`
- `X-Frame-Options: DENY`
- `Referrer-Policy: no-referrer`
- `Content-Security-Policy: frame-ancestors 'none'`
- `Strict-Transport-Security: max-age=31536000; includeSubDomains`, solo si Laravel recibe HTTPS o Traefik envia `X-Forwarded-Proto: https`.

## Frontend estatico (infraestructura)

El HSTS y la CSP Report-Only del SPA se configuran en Traefik/Dokploy, fuera de este repositorio. Ejemplo de labels listo para adaptar (reemplazar `frontend` por el nombre real del router):

```yaml
labels:
  - "traefik.http.middlewares.frontend-security.headers.stsSeconds=31536000"
  - "traefik.http.middlewares.frontend-security.headers.stsIncludeSubdomains=true"
  - "traefik.http.middlewares.frontend-security.headers.customResponseHeaders.Content-Security-Policy-Report-Only=default-src 'self'; object-src 'none'; frame-ancestors 'none'"
  - "traefik.http.routers.frontend.middlewares=frontend-security@docker"
```

Antes de convertir la CSP del SPA en obligatoria, completar sus origenes permitidos y su destino `report-uri` o `report-to` segun los servicios usados en produccion.
