# Edição pessoal

## 1.0.6 — 2026-10-15

- Identidade técnica independente `tool_salasatipicas`, incluindo tabelas, permissões, URLs, namespaces e módulos AMD.
- Removida importação automática de configurações e categorias de componentes anteriores.
- Exemplo de categoria genérico no CSV.
- Autoria e instruções de instalação e distribuição atualizadas.
- Edição beta, pendente de validação funcional em Moodle.

# Changelog

## 1.0.5 - 2026-09-21
- Mantém Abrir sala e adiciona Copiar link na mesma coluna Sala, com botões compactos empilhados.
- Copia o endereço completo do curso criado sem navegar nem exibir a URL na tabela.
- Confirma a cópia e informa falhas sem indicar sucesso quando o navegador não permite copiar.
- Inclui módulo AMD compilado, suporte à Clipboard API e alternativa para navegadores sem ela.
- Preserva todas as correções de importação e a aba Professores; sem alteração de banco ou workers.

## 1.0.4 - 2026-09-21
- Normaliza CSV UTF-8, ANSI/Windows-1252 e Unicode UTF-16/32 com BOM antes da leitura, preservando acentos e cedilha.
- Recusa conversões com perda de caracteres e planilhas com o caractere de substituição (�), antes de criar salas.
- Importa a aba Dependencias do XLSX mesmo quando o arquivo foi salvo com a aba Professores aberta; em arquivos sem essa aba, usa a primeira.
- Move o acesso à sala para uma coluna própria no histórico, com botão Abrir sala e o ID real do curso pronto.
- Ajusta as larguras das nove colunas; em telas estreitas, a rolagem fica dentro da tabela.
- Preserva a consulta de professores da versão 1.0.3 e os exemplos sem CPF pessoal.
- Não altera registros existentes, nomes de salas já criadas, banco de dados ou workers.

## 1.0.3 - 2026-09-21
- Adiciona a aba **Professores** ao modelo XLSX de importação de Dependências.
- Lista nome completo e username dos usuários ativos cujos nomes contenham `Prof.`, `Profa.` ou `Profª`.
- Mantém a listagem restrita ao download administrativo do modelo e não inclui e-mail.
- Substitui o CPF usado como exemplo nos modelos CSV e XLSX por usernames fictícios.

## 1.0.2 - 2026-08-28
- Corrige a importação em lote de Dependências na central Salas Atípicas.
- Restaura o módulo AMD `import_runner` responsável por iniciar cada linha do lote e acompanhar o worker PHP CLI.
- Sem alteração no banco de dados, templates, REA ou motor de backup/restore.

## 1.0.1 - Produção - 26/08/2026

- Ajusta o card principal do formulário de REA para ocupar 100% da largura disponível.
- Alinha visualmente a área de preenchimento do REA com a tabela de REAs recentes.
- Remove a limitação anterior de `max-width: 1180px` no card principal do REA.
- Mantém o formulário responsivo e sem alterar a lógica de criação, templates, participantes ou motor PHP CLI.
- Nenhuma alteração de banco de dados.

## 1.0.0 - Produção - 26/08/2026

- Promove a central `tool_salasatipicas` para `MATURITY_STABLE`.
- Remove os indicadores de caminho do PHP CLI dos cards da tela inicial para deixar o painel mais limpo.
- Mantém a verificação operacional dentro de cada módulo, onde ela é relevante antes da criação.
- Padroniza os cards de Dependências e REA com mesma altura, conteúdo e botões alinhados.
- O fluxo manual de Dependências passa a usar POST/Redirect/GET e abre diretamente no bloco de confirmação após `Revisar dependência`.
- Mantém o mesmo comportamento já utilizado pelo REA para revisão sem retorno ao topo da página.
- Nenhuma alteração no banco de dados ou no motor de backup/restore.
- Processamento pesado continua exclusivamente em PHP CLI destacado, sem cron, tarefas ad hoc ou backup/restore na requisição web.

## 1.0.0 RC1.1 - 26/08/2026

- Corrige a referência de autoload do importador no formulário de importação de Dependências.
- O formulário agora usa `tool_salasatipicas\\local\\dependency_importer`, classe existente na central.
- Nenhuma alteração no motor PHP CLI, banco de dados, REA ou lógica de criação.

## 1.0.0 RC1 - 2026-08-26
- Criação do componente `tool_salasatipicas`.
- Junção dos fluxos homologados de Dependência e REA em uma única central administrativa.
- Dependência preserva criação manual, lote CSV/XLSX, participantes múltiplos, histórico e retry.
- REA preserva formulário manual, disciplinas dinâmicas, personalização do template, seção do aluno e organização Material/Entrega.
- Configurações de cada fluxo permanecem isoladas para reduzir risco de regressão.
- Lock global compartilhado impede backup/restore simultâneo entre os dois módulos.
- Instalação não executa backup, restore, worker, cron ou tarefa ad hoc.
- Importação leve de configurações e categorias dos plugins antigos quando disponíveis.
