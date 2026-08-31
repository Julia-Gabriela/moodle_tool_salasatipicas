# Arquitetura

## Visão geral

O plugin separa a requisição web do processamento pesado de cópia de curso.

```text
Interface administrativa
        ↓
Validação + revisão
        ↓
Histórico / fila interna
        ↓
Processo PHP CLI destacado
        ↓
Backup + restore + personalização + matrículas
```

## Por que PHP CLI?

Backup e restore de cursos podem levar tempo suficiente para exceder o timeout HTTP de proxies ou servidores web. Por isso, o plugin não executa essas operações dentro da página do administrador.

O worker também não depende do cron para concluir uma criação recém-confirmada.

## Concorrência

Um lock global limita a execução de operações pesadas. Isso reduz o risco de múltiplos backups/restores simultâneos sobrecarregarem o ambiente.

## Módulos

### Dependências

Responsável por criação manual, importação em lote, categorias, templates, participantes e histórico.

### REA

Responsável por criação manual, dados do processo/aluno, disciplinas, professores, adaptação do template e histórico.
