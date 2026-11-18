-- MIGRAÇÃO: execute este arquivo se você JÁ importou o database.sql antes
-- (o banco existe e tem dados). Ele só cria a tabela nova de usuários e não mexe em nada existente.
-- phpMyAdmin / InfinityFree: selecione o banco primeiro e depois use "Importar".
-- Terminal: mysql -u root -p bistro_devops < migracao_usuarios.sql

CREATE TABLE IF NOT EXISTS usuarios (
 id INT UNSIGNED NOT NULL AUTO_INCREMENT,
 nome VARCHAR(120) NOT NULL,
 email VARCHAR(150) NOT NULL,
 telefone VARCHAR(11) DEFAULT NULL,
 perfil ENUM('gerente','garcom','cozinha','caixa') NOT NULL DEFAULT 'garcom',
 senha_hash VARCHAR(255) NOT NULL,
 ativo TINYINT(1) NOT NULL DEFAULT 1,
 criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (id), UNIQUE KEY uq_usuarios_email (email),
 INDEX idx_usuarios_ativo_nome (ativo,nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
