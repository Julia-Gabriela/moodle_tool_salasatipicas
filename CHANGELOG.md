# Changelog

Todas as mudanças relevantes deste projeto serão registradas neste arquivo.

## [1.0.0] - 2026-08-31

### Adicionado
- Central única para criação de Dependências e REA.
- Criação manual de Dependências.
- Importação CSV/XLSX de Dependências com processamento sequencial.
- Múltiplos professores, coordenação e alunos.
- Templates e categorias configuráveis.
- Histórico e reprocessamento de Dependências.
- Criação de REA com formulário de dados do aluno/processo.
- Disciplinas dinâmicas com professores.
- Personalização automática do template REA.
- Matrícula automática de participantes.
- Histórico de REA e histórico geral.
- Workers PHP CLI destacados para operações de backup/restore.
- Lock global para limitar concorrência de processos pesados.

### Segurança e confiabilidade
- Backup/restore fora da requisição web.
- Sem dependência de cron/ad-hoc para concluir novas criações.
- Rejeição de PHP-FPM/CGI como worker CLI.
- Validações de categoria, template, usuários, papéis e duplicidade.
