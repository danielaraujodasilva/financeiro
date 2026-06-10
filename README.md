# Financeiro

Base inicial do sistema financeiro com:

- login por usuário
- instâncias separadas por conta/organização
- suporte a conta conjunta via membros
- webhook de sincronização com GitHub

## Estrutura

- `public/` ou arquivos na raiz: entrada da aplicação
- `app/`: código da aplicação
- `data/`: banco local e arquivos gerados

## Modelo de acesso

- cada usuário pode pertencer a uma ou mais instâncias
- cada instância tem um `owner`
- o `owner` pode convidar outros usuários
- membros convidados acessam apenas as instâncias em que foram adicionados

## Próximo passo

Montar as telas de login, cadastro, criação de instância e convite de membros.
