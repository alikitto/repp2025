-- 04_seed.sql — admin / change_me (первый вход мигрирует в bcrypt)
INSERT INTO users (login, password, name, familiya, prof)
VALUES ('admin', 'change_me', 'Admin', 'User', 'owner');
