# Salas Atípicas para Moodle

Plugin administrativo (`tool_salasatipicas`) para **Moodle 5.0+** que centraliza a criação automatizada de dois tipos de salas acadêmicas que normalmente exigem muitas etapas manuais:

- **Dependências** — criação manual ou em lote, baseada em templates, categorias e participantes configuráveis.
- **REA** — criação de salas com dados do aluno/processo, disciplinas dinâmicas, professores, personalização do template e matrículas automáticas.

O objetivo é reduzir trabalho repetitivo sem alterar o core do Moodle e sem executar backup/restore dentro da requisição HTTP do administrador.

## Principais recursos

### Dependências

- criação manual;
- importação em lote por CSV e XLSX;
- validação antes da criação;
- múltiplos professores, coordenação e alunos;
- papéis definidos nas configurações;
- template global ou template específico por categoria;
- nome completo e nome breve configuráveis;
- proteção contra duplicidade;
- histórico e reprocessamento;
- processamento sequencial do lote.

### REA

- formulário administrativo para os dados exibidos na sala;
- seleção separada do usuário Moodle que será matriculado;
- disciplinas dinâmicas com um ou mais professores;
- coordenação opcional;
- template e categoria configuráveis;
- preenchimento automático da área de informações do template;
- renomeação da seção do aluno;
- organização dos pares **Material da Disciplina / Entrega da Atividade REA** por disciplina;
- matrícula automática de aluno, professores e coordenação;
- histórico próprio de processamento;
- o plugin **não envia nem substitui materiais didáticos**.

## Arquitetura

O processamento pesado é isolado da requisição web:

```text
Administrador
    │
    ▼
Formulário / validação / revisão
    │
    ▼
Registro no histórico
    │
    ▼
Worker PHP CLI independente
    │
    ├── backup do template
    ├── restore do curso
    ├── personalização
    └── matrículas
    │
    ▼
Sala pronta + histórico atualizado
```

A arquitetura foi desenhada para evitar timeouts de gateway durante backup/restore.

- não depende do cron para concluir novas criações;
- não usa tarefas ad hoc para o fluxo de criação;
- backup/restore não roda na requisição do navegador;
- rejeita PHP-FPM/CGI como worker;
- usa lock global para evitar múltiplos backup/restores pesados simultâneos.

## Requisitos

- Moodle 5.0 ou superior;
- PHP CLI disponível no servidor;
- permissão do processo web para iniciar o worker PHP em segundo plano;
- método de inscrição manual habilitado;
- template(s) de curso previamente configurado(s);
- papéis Moodle válidos no contexto de curso.

## Instalação

Copie a pasta `salasatipicas` para:

```text
/admin/tool/salasatipicas/
```

Depois acesse a administração do Moodle e conclua a atualização dos plugins.

O acesso principal fica em:

```text
Administração do site → Cursos → Gerenciar salas atípicas
```

Após instalar:

1. limpe os caches do Moodle;
2. configure os papéis de aluno, professor e coordenação;
3. cadastre as categorias de destino;
4. informe os IDs dos templates;
5. faça uma criação controlada antes de usar em escala.

> Esta distribuição é independente e não migra automaticamente configurações ou históricos de outros plugins.

## Importação de Dependências

O módulo aceita CSV/XLSX com as colunas:

```text
processo | disciplina | professor | coordenacao | aluno | periodo | categoria
```

Para informar mais de um usuário na mesma célula, use `|`:

```text
professor.um|professor.dois
```

Recomenda-se usar o modelo XLSX gerado pelo próprio plugin para preservar zeros à esquerda e evitar conversões automáticas de datas/períodos pelo Excel.

## Configuração de REA

O template REA deve conter os marcadores configurados na administração, por padrão:

```text
Aluno (a):
Aluno: xxx
Material da Disciplina
Entrega da Atividade REA - Disciplina
```

O plugin localiza essas estruturas após o restore e adapta a sala conforme os dados informados no formulário.

## Segurança operacional

- acesso restrito por capability de sistema;
- validação de template, categoria, usuários e papéis;
- confirmação antes da criação;
- lock de processamento pesado;
- tentativa de limpeza de curso incompleto em falhas críticas;
- histórico operacional para diagnóstico;
- processamento pesado exclusivo do worker CLI.

## Estrutura do projeto

```text
salasatipicas/
├── amd/                    # JavaScript/AMD do lote
├── classes/
│   ├── event/              # Eventos de auditoria
│   ├── form/               # Formulários Moodle
│   ├── local/              # Regras de negócio
│   └── privacy/            # Privacy API
├── cli/                    # Workers CLI
├── db/                     # XMLDB, capabilities e upgrades
├── lang/                   # pt-BR e inglês
├── dependency.php          # Gerenciamento de Dependências
├── rea.php                 # Gerenciamento de REA
├── history.php             # Histórico geral
├── index.php               # Central do plugin
└── styles.css              # Interface administrativa
```

## Status

Versão pública inicial: **1.0.0**.

Antes de usar em produção, valide o plugin no seu próprio ambiente de homologação, principalmente templates, categorias, papéis, permissões do PHP CLI e comportamento do proxy/webserver.

## Licença

GPL-3.0-or-later, seguindo a licença do Moodle.
