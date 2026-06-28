-- Runs once on first cluster init. Creates the dedicated database used by the
-- integration test suite so it never collides with local development data.
CREATE DATABASE ledger_test OWNER ledger;
