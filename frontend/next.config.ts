import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  // Дозволяємо доступ до dev-сервера з телефона
  allowedDevOrigins: ["192.168.50.191"],
  async rewrites() {
    const backend = (
        process.env.API_INTERNAL_URL ??
        "http://127.0.0.1:8000"
    ).replace(/\/+$/, "");

    return [
      {
        source: "/storage/products/:path*",
        destination: `${backend}/storage/products/:path*`,
      },
    ];
  },
  async redirects() {
    return [
      {
        source: "/",
        destination: "/uk",
        permanent: false,
      },
    ];
  },
  /* config options here */
  cacheComponents: true,
  partialPrefetching: true,
  turbopack: {
    rules: {
      "*.css": {
        loaders: ["@tailwindcss/turbopack"],
        as: "*.css",
      },
    },
  },
};

export default nextConfig;
