# ── Stage 1: Build ──────────────────────────────────────────────────────────
FROM node:20-alpine AS builder

WORKDIR /app

# Install dependencies with lockfile enforcement (reproducible builds)
COPY package*.json ./
RUN npm ci

# Copy source and compile
COPY . .
RUN npm run build

# ── Stage 2: Serve ──────────────────────────────────────────────────────────
FROM nginx:stable-alpine AS runner

# Remove nginx defaults so no stale files remain
RUN rm -rf /usr/share/nginx/html/*

# Copy ONLY the compiled output — never the source tree
COPY --from=builder /app/dist /usr/share/nginx/html

# Replace the entire nginx config with our SPA-aware version
COPY nginx.conf /etc/nginx/nginx.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]
