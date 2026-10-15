# Salas Atípicas

Admin tool para Moodle 5.0 que reúne, em uma única central, os fluxos de **Dependências** e **REA**.

## Objetivo

O plugin mantém as duas automações independentes internamente, mas oferece um único acesso em **Administração do site → Cursos → Gerenciar salas atípicas**.

### Dependências
Na versão 1.0.5, a coluna **Sala** reúne **Abrir sala** e **Copiar link**. O segundo botão copia o endereço completo do curso pronto para a área de transferência, sem abrir outra página, e confirma com “Link copiado!”. Se o navegador negar a cópia, uma mensagem orienta a alternativa manual. Valide ambos os botões no navegador do ambiente de teste antes de atualizar produção.

Na versão 1.0.4, CSVs do Excel em UTF-8 ou ANSI/Windows-1252 são normalizados antes da importação. Unicode UTF-16/32 com BOM também é aceito. Se a planilha já contém `�`, corrija a fonte original; o importador impede novas criações a partir desse texto. Nomes já gravados no Moodle não são alterados automaticamente.

O histórico possui uma coluna **Sala** com o botão **Abrir sala** para registros prontos com ID de curso. O endereço usa o domínio configurado no Moodle e `/course/view.php?id=ID_REAL`.

- criação manual;
- importação em lote CSV/XLSX;
- modelo XLSX com aba auxiliar de professores ativos (nome completo e username);
- múltiplos professores, coordenação e alunos;
- templates e categorias configuráveis;
- histórico e reprocessamento;
- nome completo e nome breve sem encurtamento automático.

### REA
- criação manual;
- dados do processo/aluno preenchidos pelo formulário;
- disciplinas dinâmicas com professores;
- personalização da Área de texto e mídia do template;
- renomeação da seção `Aluno: xxx`;
- organização dos pares `Material da Disciplina` + `Entrega da Atividade REA`;
- matrícula automática de aluno, professores e coordenação;
- não envia nem substitui materiais didáticos.

## Segurança operacional

### Validação da versão 1.0.4

Antes de atualizar produção, valide no Moodle de teste: envie arquivos CSV UTF-8 e ANSI com `Ética`, `Programação`, `AÇÃO` e `Extensão 3ª`; confira a prévia e o nome de uma sala de teste criada. Salve também um XLSX com a aba Professores aberta e confirme que os dados são lidos de Dependencias. No histórico, o botão deve abrir o ID da sala pronta; linhas pendentes ou com erro não devem exibir esse botão. Confira o layout no tema instalado e em tela estreita. Não reimporte salas de produção apenas para corrigir os nomes existentes.

Os testes automatizados estão em `tests/dependency_importer_test.php`. Em uma instalação de desenvolvimento do Moodle com PHPUnit inicializado, execute `vendor/bin/phpunit admin/tool/salasatipicas/tests/dependency_importer_test.php`. Esta entrega não foi executada em um servidor Moodle; a validação em ambiente de teste continua necessária.

As duas automações utilizam **PHP CLI destacado** para backup/restore. O navegador valida, registra a solicitação e dispara o worker; ele nunca executa o processo pesado na requisição HTTP.

- sem cron;
- sem tarefas ad hoc;
- sem backup/restore no navegador;
- rejeita PHP-FPM/CGI como worker;
- lock global compartilhado entre Dependência e REA: somente uma criação pesada desta central é processada por vez.

## Autoria e distribuição

Desenvolvido por **Júlia Gabriela Gomes da Silva**. Licença GNU GPL v3 ou posterior; consulte `LICENSE`.

## Edição pessoal 1.0.6

Componente: `tool_salasatipicas`. Pasta: `admin/tool/salasatipicas`.

Esta edição tem identidade, tabelas, permissões e configurações próprias. Instale como um novo plugin; ela não atualiza nem migra dados de componentes com outros nomes. Configure categorias, templates e papéis antes do uso. Nenhum dado de configuração ou histórico é importado automaticamente.

1. Copie a pasta `salasatipicas` para `admin/tool`.
2. Acesse Administração do site → Notificações.
3. Configure Dependências e REA e valide os fluxos em teste.

Para Git, coloque o conteúdo desta pasta na raiz do repositório e versione também `amd/build`.

## Verificação desta entrega

Conferidos nomes técnicos, referências de tabelas, XML de instalação, módulos JavaScript e integridade do ZIP. Não foi possível executar PHP, PHPUnit ou testar backup/restore em Moodle neste ambiente. O checklist é um roteiro de validação, não um registro de testes aprovados. A edição permanece marcada como beta até validação funcional.
