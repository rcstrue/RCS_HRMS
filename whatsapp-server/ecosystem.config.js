module.exports = {
    apps: [{
        name: 'whatsapp-bot',
        script: 'server.js',
        cwd: __dirname,
        instances: 1,
        autorestart: true,
        watch: false,
        max_memory_restart: '500M',
        env: {
            NODE_ENV: 'production',
            PORT: 3000,
            API_KEY: 'rcs-hrms-secret-key-2026',
        },
        error_file: './logs/error.log',
        out_file: './logs/out.log',
        time: true,
        merge_logs: true,
        log_date_format: 'YYYY-MM-DD HH:mm:ss',
    }]
};