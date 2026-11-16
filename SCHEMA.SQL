-- Tabela: usuários
CREATE TABLE public."usuários" (
  id uuid NOT NULL PRIMARY KEY,
  nome varchar,
  "e-mail" varchar UNIQUE,
  senha_hash varchar,
  criado_em timestamptz DEFAULT now()
);

-- Tabela: conversas
CREATE TABLE public.conversas (
  id uuid NOT NULL PRIMARY KEY,
  id_do_usuário uuid REFERENCES auth.users(id),
  titulo text,
  criado_em timestamptz DEFAULT now()
);

-- Tabela: mensagens
CREATE TABLE public.mensagens (
  id uuid NOT NULL PRIMARY KEY,
  conversar_id uuid REFERENCES public.conversas(id),
  referente text,
  "conteúdo" text,
  criado_em timestamptz DEFAULT now()
);
