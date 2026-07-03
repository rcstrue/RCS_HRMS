# Baileys WhatsApp Integration for RCS HRMS

## Project

RCS HRMS

Website

https://join.rcsfacility.com

Notification Settings Page

https://join.rcsfacility.com/hrms/index.php?page=settings/notifications

---

# Objective

Integrate Baileys WhatsApp with RCS HRMS so that:

- Manual WhatsApp messages can be sent to employees.
- Automatic notifications can be sent.
- Existing Notification Settings page can control the integration.
- QR is scanned only once.
- WhatsApp remains connected 24×7.
- PHP communicates with Baileys using REST API.

---

# Current Status

✔ HRMS is PHP

✔ Baileys installed

✔ Session authentication completed

✔ QR scanned

✔ Test message successfully sent

Need to convert Baileys into a REST API server.

---

# Architecture

                HRMS (PHP)
                      │
                      │ HTTP REST
                      ▼
          Node.js + Baileys API
                      │
                      ▼
                WhatsApp Web
                      │
                      ▼
                  Employee

PHP should NEVER execute bun/node every time.

Baileys must remain connected continuously.

---

# Folder Structure

/home/rcsfaxhz/

    whatsapp/
        server.js
        session/
        package.json
        routes/
        uploads/
        logs/

HRMS remains unchanged.

---

# Required Endpoints

## GET /

Returns

```json
{
    "success": true,
    "status": "online",
    "connected": true,
    "version": "1.0"
}
```

Used by Notification Settings page to display

🟢 Online

or

🔴 Offline

---

## GET /health

Returns

```json
{
    "connected": true
}
```

---

## GET /qr

Returns current QR image.

If already connected:

```json
{
    "connected": true
}
```

---

## POST /send

Headers

```
x-api-key: YOUR_API_KEY
```

Body

```json
{
    "number":"919824009110",
    "message":"Hello Employee"
}
```

Response

```json
{
    "success":true
}
```

---

## POST /send-image

Body

```json
{
    "number":"919824009110",
    "image":"https://join.rcsfacility.com/uploads/photo.jpg",
    "caption":"Attendance Report"
}
```

---

## POST /send-document

Body

```json
{
    "number":"919824009110",
    "file":"https://join.rcsfacility.com/uploads/slips/salary.pdf",
    "filename":"Salary Slip.pdf"
}
```

---

## POST /send-bulk

Body

```json
{
    "numbers":[
        "919824009110",
        "919824009111",
        "919824009112"
    ],
    "message":"Tomorrow Holiday"
}
```

Messages should be queued.

Delay between each message

2-5 seconds

to avoid WhatsApp spam detection.

---

# API Authentication

Every request must include

```
x-api-key
```

Example

```
RCS_HRMS_SECRET_KEY
```

Reject invalid API keys.

Return

HTTP 401

---

# Notification Settings Integration

Existing page

https://join.rcsfacility.com/hrms/index.php?page=settings/notifications

Fields

Bot API URL

Example

```
http://127.0.0.1:3000
```

or

```
https://bot.join.rcsfacility.com
```

API Key

```
RCS_HRMS_SECRET_KEY
```

Save in database.

---

# Connection Test

When Notification Settings page opens

Call

GET

```
BOT_URL/
```

If response

```json
{
    "connected":true
}
```

Display

🟢 Online

Else

🔴 Offline

---

# PHP Helper

Create

includes/whatsapp.php

Functions

sendWhatsApp()

sendImage()

sendDocument()

sendBulk()

Every module should call helper only.

No direct cURL code inside business logic.

---

# Automatic Notifications

Examples

Employee Created

↓

Send Welcome Message

Leave Approved

↓

Send Notification

Leave Rejected

↓

Send Notification

Expense Approved

↓

Send Notification

Salary Processed

↓

Send Salary Slip PDF

Attendance Alert

↓

Send Reminder

OTP

↓

Send Verification Code

Birthday

↓

Send Greeting

Work Anniversary

↓

Send Greeting

---

# Manual Messaging

Create new HRMS module

Communication → WhatsApp

Features

✓ Single Employee

✓ Multiple Employees

✓ Entire Client

✓ Entire Unit

✓ Department

✓ Search Employee

✓ Upload Excel Numbers

✓ Attach PDF

✓ Attach Image

✓ Message Templates

✓ Schedule Message

✓ Send History

✓ Failed Messages

---

# Logging

Every message

Store

Employee ID

Mobile

Message

Date

Status

Error

Message ID

Table

whatsapp_logs

---

# Queue

Never send hundreds of messages simultaneously.

Implement queue

Example

1 message every 2 seconds

Retry failed messages.

---

# Background Service

Run using PM2

Example

pm2 start server.js

pm2 save

pm2 startup

Baileys should reconnect automatically after reboot.

---

# Security

Only localhost or HRMS should access API.

Require API key.

Enable CORS only for

https://join.rcsfacility.com

Never expose session credentials.

---

# Future Features

- Receive incoming messages

- Read receipts

- Delivery status

- Group messaging

- Contact sync

- Voice messages

- Video

- Location

- Polls

- Interactive buttons

- AI chatbot integration

---

# Final Goal

RCS HRMS should use Baileys as its internal WhatsApp Gateway.

The Notification Settings page will only require:

Bot API URL

API Key

After saving, every HRMS module can send WhatsApp messages through the centralized Baileys REST API without modifying the Baileys connection.