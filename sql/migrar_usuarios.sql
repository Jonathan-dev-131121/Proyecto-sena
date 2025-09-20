
BEGIN;


ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS usuario VARCHAR(100);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS clave VARCHAR(255);
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS tipo_usuario VARCHAR(50) DEFAULT 'operario';


DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='usuarios' AND column_name='usuario') THEN
        UPDATE usuarios SET username = usuario WHERE username IS NULL;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='usuarios' AND column_name='clave') THEN
        -- Si las contraseñas estaban guardadas con crypt(gen_salt()), conservamos el valor en 'password'
        UPDATE usuarios SET password = clave WHERE password IS NULL;
    END IF;
    IF EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='usuarios' AND column_name='tipo_usuario') THEN
        UPDATE usuarios SET rol = tipo_usuario WHERE rol IS NULL;
    END IF;
END$$;


CREATE TABLE IF NOT EXISTS user_audit (
    id SERIAL PRIMARY KEY,
    usuario_id INTEGER,
    accion VARCHAR(50) NOT NULL,
    detalles TEXT,
    realizado_por VARCHAR(100),
    creado_at TIMESTAMP WITH TIME ZONE DEFAULT now()
);

COMMIT;

