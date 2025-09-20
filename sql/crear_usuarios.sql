-- Script de creación de tabla 'usuarios' para PostgreSQL

CREATE TABLE IF NOT EXISTS usuarios (
    usuario VARCHAR(100) UNIQUE NOT NULL PRIMARY KEY,
    clave VARCHAR(255) NOT NULL,
    tipo_usuario VARCHAR(50) DEFAULT 'operario',
    creado_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);

-- Índice para búsquedas por usuario (ya cubierto por UNIQUE)
CREATE UNIQUE INDEX IF NOT EXISTS idx_usuarios_usuario ON usuarios(usuario);
