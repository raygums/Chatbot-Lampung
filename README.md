# **Chatbot Lampung**

A Laravel-based WhatsApp bot backend that receives incoming webhook messages, matches user queries, and replies with regional public service information for the Lampung area.

## **Overview**

* Listens to incoming webhooks from WhatsApp gateways (Fonnte, Wablas, Meta Cloud API, etc.).  
* Parses incoming text payloads and routes them to keyword handlers in WhatsappController.  
* Logs message histories and tracks conversation state in MySQL.  
* Includes Blade templates for inspecting message logs and inbound activity.

## **Requirements**

* PHP 8.1 or higher  
* Composer  
* MySQL or MariaDB  
* Ngrok or Cloudflare Tunnel (needed to forward webhooks to localhost during development)

## **Directory Layout**

Chatbot-Lampung/  
├── app/Http/Controllers/  
│   └── WhatsappController.php   \# Inbound webhook processing & dispatch logic  
├── database/  
│   ├── migrations/              \# Schema for logs, user sessions, and inbound texts  
│   └── seeders/                 \# Bot replies and sample keyword datasets  
├── resources/views/             \# Admin log viewer (Blade templates)  
├── routes/  
│   ├── api.php                  \# Webhook routes (exempt from CSRF verification)  
│   └── web.php                  \# Web interface and dashboard routes  
└── .env.example

## **Setup & Installation**

### **1\. Clone & Install Dependencies**

git clone https://github.com/raygums/Chatbot-Lampung.git  
cd Chatbot-Lampung  
composer install

### **2\. Environment Setup**

cp .env.example .env  
php artisan key:generate

Open .env and configure your database and provider credentials:

DB\_CONNECTION=mysql  
DB\_HOST=127.0.0.1  
DB\_PORT=3306  
DB\_DATABASE=chatbot\_lampung  
DB\_USERNAME=root  
DB\_PASSWORD=

\# WhatsApp Provider Configuration  
WA\_API\_URL=https://api.yourprovider.com/v1  
WA\_API\_TOKEN=your\_token\_here  
WA\_VERIFY\_TOKEN=your\_webhook\_verification\_secret

### **3\. Run Migrations**

php artisan migrate

### **4\. Start the Application**

php artisan serve

## **Webhook Setup (Local Testing)**

WhatsApp gateway providers cannot reach http://localhost:8000 directly. You need a public HTTPS tunnel:

1. Expose your local port with Ngrok:  
   ngrok http 8000

2. Copy the forwarding URL and enter it in your WhatsApp provider dashboard:  
   https://\<your-subdomain\>.ngrok-free.app/api/webhook/whatsapp

3. Ensure the verification token configured in the provider dashboard matches WA\_VERIFY\_TOKEN in .env.

> **Note:** The webhook endpoint is registered under routes/api.php so Laravel does not block incoming POST payloads with CSRF checks.

## **Author**

* [raygums](https://github.com/raygums)
