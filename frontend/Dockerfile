# ── Stage 1: Build ──────────────────────────────────────────────────────────
FROM node:20-alpine AS builder

WORKDIR /app

# Install dependencies with lockfile enforcement (reproducible builds)
COPY package*.json ./
RUN npm ci

# Copy source and compile
COPY . .

# Vite bakes VITE_* env vars at build time — pass the production API URL here.
# Override with: docker build --build-arg VITE_API_BASE_URL=https://...
ARG VITE_API_BASE_URL=https://refactor.kyrocodelabs.cloud/api
ENV VITE_API_BASE_URL=$VITE_API_BASE_URL

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
