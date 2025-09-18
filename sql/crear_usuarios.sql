-- Script de creación de tabla 'usuarios' para PostgreSQL

CREATE TABLE IF NOT EXISTS usuarios (
    id SERIAL PRIMARY KEY,
    username VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    rol VARCHAR(50) DEFAULT 'usuario',
    creado_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);

-- Índice para búsquedas por username (ya cubierto por UNIQUE)
CREATE UNIQUE INDEX IF NOT EXISTS idx_usuarios_username ON usuarios(username);
