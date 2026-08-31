# Dependências

## Criação manual

O formulário permite informar processo, disciplina, professores, coordenação, alunos, período e categoria de destino.

Os papéis de cada grupo são definidos nas configurações e aplicados automaticamente.

## Importação em lote

Colunas:

```text
processo | disciplina | professor | coordenacao | aluno | periodo | categoria
```

Múltiplos usuários são separados por `|`.

Exemplo:

```text
professor.um|professor.dois
```

O lote é validado antes da criação e processado de forma sequencial.
