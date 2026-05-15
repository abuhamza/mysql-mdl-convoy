-- Enable metadata lock instrumentation in Performance Schema.
-- This is disabled by default in MySQL 8.0 to save overhead.
UPDATE performance_schema.setup_instruments
   SET ENABLED = 'YES', TIMED = 'YES'
 WHERE NAME = 'wait/lock/metadata/sql/mdl';

-- Make sure the metadata_locks consumer is on as well.
UPDATE performance_schema.setup_consumers
   SET ENABLED = 'YES'
 WHERE NAME IN ('global_instrumentation', 'thread_instrumentation');

USE mdl_demo;

DROP TABLE IF EXISTS sensor_data;

CREATE TABLE sensor_data (
    id         BIGINT       NOT NULL AUTO_INCREMENT,
    sensor_id  INT          NOT NULL,
    ts         TIMESTAMP    NOT NULL,
    payload    VARCHAR(64)  NOT NULL,
    PRIMARY KEY (id, ts)
)
ENGINE=InnoDB
PARTITION BY RANGE (UNIX_TIMESTAMP(ts)) (
    PARTITION p1 VALUES LESS THAN (UNIX_TIMESTAMP('2026-04-01 00:00:00')),
    PARTITION p2 VALUES LESS THAN (UNIX_TIMESTAMP('2026-05-01 00:00:00')),
    PARTITION p3 VALUES LESS THAN (UNIX_TIMESTAMP('2026-06-01 00:00:00')),
    PARTITION p4 VALUES LESS THAN (UNIX_TIMESTAMP('2026-07-01 00:00:00')),
    PARTITION pmax VALUES LESS THAN MAXVALUE
);

-- Seed each partition so TRUNCATE PARTITION has something to do.
INSERT INTO sensor_data (sensor_id, ts, payload) VALUES
    (1, '2026-03-15 12:00:00', 'seed-p1'),
    (1, '2026-04-15 12:00:00', 'seed-p2'),
    (1, '2026-05-15 12:00:00', 'seed-p3'),
    (1, '2026-06-15 12:00:00', 'seed-p4');

-- Grant the demo user access to performance_schema so the monitor can run as `demo`.
GRANT SELECT ON performance_schema.* TO 'demo'@'%';
GRANT SELECT ON sys.* TO 'demo'@'%';
GRANT PROCESS ON *.* TO 'demo'@'%';
FLUSH PRIVILEGES;
