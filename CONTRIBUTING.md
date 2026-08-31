# Contribuindo

Contribuições são bem-vindas.

## Fluxo sugerido

1. Faça um fork do repositório.
2. Crie uma branch para a alteração.
3. Teste em um ambiente Moodle de homologação.
4. Rode lint de PHP e valide o XMLDB.
5. Abra um Pull Request descrevendo problema, solução e testes realizados.

## Cuidados importantes

Alterações no mecanismo de cópia de curso devem preservar a regra de arquitetura do projeto: **backup/restore não deve ser executado dentro da requisição HTTP do administrador**.

Mudanças de banco devem seguir as convenções XMLDB do Moodle e incluir upgrade compatível quando necessário.
