# AI Chat Assistant for EC-CUBE 4.2 / 4.3

![AI Chat Assistant for EC-CUBE 4.2/4.3](Resource/images/readme-hero.png)

![EC-CUBE](https://img.shields.io/badge/EC--CUBE-4.2%20%7C%204.3-orange)
![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-777BB4)
![License](https://img.shields.io/badge/license-GPL--2.0--only-green)
![MCP](https://img.shields.io/badge/MCP-Streamable%20HTTP-2ec9bb)
![WebMCP](https://img.shields.io/badge/WebMCP-Supported-2ec9bb)
![E2E](https://img.shields.io/badge/E2E-Playwright-brightgreen)

**An open-source AI commerce assistant for EC-CUBE that answers customer questions using your product catalog and store-specific knowledge.**

It supports OpenAI, Anthropic Claude, and Google Gemini, enabling AI-powered product discovery, product comparison, stock inquiries, and multi-turn conversations directly within your EC-CUBE storefront.

It also supports both **MCP (Model Context Protocol) and WebMCP**, allowing EC-CUBE capabilities to be exposed not only through the traditional chat interface, but also to external MCP clients, AI agents, and browser-based AI.

[日本語 README](Documents/README.ja.md)

---

## Overview

Online stores repeatedly receive questions from customers before they make a purchase:

> "Is this product in stock?"

> "Which product would you recommend for a beginner?"

> "What's the difference between these two products?"

> "Can you recommend something within my budget?"

AI Chat Assistant uses your EC-CUBE product data and store-specific knowledge to answer these questions in natural language.

It maintains conversational context within the same session, enabling follow-up interactions such as:

```text
"Show me some products for beginners."
              ↓
"Which one is the cheapest?"
              ↓
"Is that one in stock?"
```

When AI alone cannot resolve a customer's question, the conversation can also be escalated to human support.

**The plugin goes beyond automated product guidance: it provides conversation analytics, FAQ improvement workflows, and human escalation directly from the EC-CUBE administration panel.**

---

# Philosophy

We believe that technical debt is not limited to software.

Systems and structures that remain in place for long periods without being reconsidered can accumulate something similar to technical debt at a societal level.

Through open-source development, publication, improvement, and discussion, we aim to explore how technology can contribute back to society.

You can read more about this idea here:

[Read: Technical Debt Exists in Society, Too](https://www.thch-vape.shop/guide/column/git-log--oneline--all--society)

---

# Key Features

* AI-powered product search and guidance
* Product comparison and stock inquiries
* Multi-turn conversations
* OpenAI support
* Anthropic Claude support
* Google Gemini support
* Store-specific knowledge base
* Hybrid AI + predefined responses
* Conversation history
* Usage analytics and reports
* Human support escalation by email
* Notifications
* Access control
* MCP Server
* MCP Streamable HTTP
* WebMCP
* MCP Discovery
* Rate limiting
* Security protections
* Responsive desktop and mobile interface

---

# AI-Powered Product Guidance

AI Chat Assistant uses your EC-CUBE product data to help customers discover and compare products.

It can work with information such as:

* Product names
* Prices
* Stock availability
* Categories
* Product search
* Product comparison
* Multi-turn conversation context

Unlike a generic AI chatbot, the assistant can answer questions using the actual commerce data registered in your EC-CUBE store.

---

# Three AI Providers

The plugin supports three major AI providers.

### OpenAI

Use supported OpenAI models.

### Anthropic

Use supported Anthropic Claude models.

### Google Gemini

Use supported Google Gemini models.

The provider and model can be selected from the EC-CUBE administration panel.

Model definitions can also be updated through an external JSON file, allowing new AI models to be added without requiring a plugin release.

---

# MCP / WebMCP

AI Chat Assistant supports both **MCP and WebMCP** in addition to the standard storefront chat interface.

```text
                    ┌─────────────────┐
                    │     EC-CUBE     │
                    │ Commerce Data   │
                    └────────┬────────┘
                             │
               ┌─────────────┼─────────────┐
               │             │             │
               ▼             ▼             ▼
         Chat Widget     MCP Server      WebMCP
               │             │             │
               ▼             ▼             ▼
           Customer      MCP Client    Browser / AI
                             │
                             ▼
                          AI Agent
```

This architecture makes EC-CUBE commerce data and capabilities available through multiple AI interfaces.

---

## MCP Server

The plugin provides an MCP Server that allows external MCP-compatible clients and AI agents to interact with EC-CUBE.

### Transport

```text
Streamable HTTP
```

### Endpoints

```text
POST /mcp
GET /.well-known/mcp.json
```

### MCP Operations

The server supports core MCP operations including:

```text
initialize
tools/list
tools/call
```

An MCP client can connect to the server, discover the available tools, and invoke EC-CUBE capabilities through those tools.

```text
AI Agent
    │
    ▼
initialize
    │
    ▼
tools/list
    │
    ▼
Discover available tools
    │
    ▼
tools/call
    │
    ▼
EC-CUBE
```

---

## MCP Discovery

The plugin exposes MCP discovery information through:

```text
GET /.well-known/mcp.json
```

MCP clients and AI agents can use this endpoint to discover the MCP Server endpoint and transport information.

---

## WebMCP

The plugin also supports **WebMCP**.

While the MCP Server exposes EC-CUBE capabilities to external AI agents through a server-side interface, WebMCP provides an interface between the EC-CUBE storefront and AI running in or interacting with the browser.

```text
EC-CUBE Storefront
        │
        ▼
      WebMCP
        │
        ▼
Browser / AI Agent
```

This makes it possible to move beyond the traditional interaction model:

```text
Human
  ↓
Chat Widget
  ↓
AI
  ↓
EC-CUBE
```

and support another path:

```text
Browser AI / AI Agent
        ↓
      WebMCP
        ↓
     EC-CUBE
```

EC-CUBE capabilities can therefore be exposed directly to AI agents operating in the context of the storefront.

---

## MCP vs. WebMCP

|                     | MCP Server                                  | WebMCP                                       |
| ------------------- | ------------------------------------------- | -------------------------------------------- |
| Primary environment | Server                                      | Browser / Web page                           |
| Primary consumer    | MCP clients / AI agents                     | Browser-based AI                             |
| EC-CUBE connection  | HTTP MCP endpoint                           | Storefront                                   |
| Main purpose        | Expose commerce capabilities to external AI | Expose page/store capabilities to browser AI |
| Plugin support      | Supported                                   | Supported                                    |

By supporting both, EC-CUBE can evolve from an **e-commerce platform with an AI chatbot** into a **commerce platform that AI agents can interact with directly**.

---

# Hybrid AI + Predefined Responses

Not every customer question needs to be sent to an AI model.

Questions with deterministic answers, such as those about returns, shipping fees, or business hours, can be handled using predefined scenarios.

```text
Customer
   │
   ▼
Question
   │
   ▼
Scenario match?
   │
   ├── YES ──→ Predefined response
   │
   └── NO
        │
        ▼
       AI
        │
        ├── Product data
        └── Store knowledge
             │
             ▼
           Answer
```

Because the AI API is not called for predefined responses, scenarios can help:

* Reduce AI API costs
* Improve response speed
* Keep deterministic answers consistent

---

# Quick Start

You do not need to configure every feature to start using the basic AI chat functionality.

## 1. Install the Plugin

Download the plugin package from GitHub Releases or another supported distribution channel and install it into EC-CUBE.

Using the CLI:

```bash
php bin/console eccube:plugin:install --code=AiChatAssistant42
php bin/console eccube:plugin:enable --code=AiChatAssistant42
```

The plugin can also be enabled from the EC-CUBE administration panel.

---

## 2. Configure an API Key

Open the plugin settings from the EC-CUBE administration panel.

```text
Settings
└── AI Chat Assistant
      └── Plugin Settings
```

Configure an API key for one of the supported providers:

* OpenAI
* Anthropic
* Google Gemini

---

## 3. Enable Chat

Turn on **Enable Chat**.

The AI chat widget will appear on the storefront.

**That's all you need for the basic setup.**

Knowledge, scenarios, notifications, access controls, and other features can be configured as needed.

---

# Customer-Facing Features

## Responsive Chat

The chat widget supports both desktop and mobile devices.

The following properties can be customized to match your storefront:

* Widget color
* Size
* Position
* AI assistant display name
* Initial message

---

## Multi-Turn Conversations

Conversation history is retained within the same session so the assistant can maintain context.

For example:

```text
"Show me products for beginners."
          ↓
"Which one is the cheapest?"
          ↓
"Is that one in stock?"
```

These questions can be handled as a single continuous conversation.

---

## Human Support Escalation

When AI chat cannot resolve a question, the customer can request a response from the store.

```text
AI Chat
   │
   ▼
Unresolved
   │
   ▼
Request email response
   │
   ▼
Store staff
   │
   ▼
Human support
```

The system is designed to hand unresolved conversations over to humans rather than assuming that AI should handle every support request.

---

# Administration Panel

AI configuration, operations, and analytics can all be managed from the EC-CUBE administration panel.

| Page                 | Main Functions                                                    |
| -------------------- | ----------------------------------------------------------------- |
| Dashboard            | Conversations, resolution rate, error rate, average response time |
| Plugin Settings      | AI provider, model, API key, system prompt                        |
| Chat History         | Customer and AI conversations                                     |
| Statistics & Reports | Provider, model, and time-based analytics                         |
| Knowledge Management | FAQs and store-specific information                               |
| Scenario Management  | Keyword-triggered predefined responses                            |
| Access Rules         | IP, time range, blocked words                                     |
| Design Settings      | Color, size, position, display name                               |
| Notification Rules   | Email, Webhook, LINE notifications                                |

---

# Dashboard

The dashboard provides visibility into AI chat operations.

Key metrics include:

* Total conversations
* Resolution rate
* Error rate
* Average response time
* Usage by AI provider
* Usage by AI model
* Requests by time period
* Pending human responses

The goal is not simply to deploy an AI chatbot, but to establish an improvement cycle:

```text
Usage
  ↓
Measurement
  ↓
Analysis
  ↓
Discover common questions
  ↓
Improve knowledge / scenarios
  ↓
Improve answer quality
```

---

# Knowledge Management

Store-specific information that is not available in the EC-CUBE product catalog can be registered as knowledge.

For example:

```text
Title:
Returns and Exchanges

Category:
Returns

Content:
Returns are accepted within seven days of delivery
for unopened products.
```

The AI can then use this information when answering customer questions.

Typical use cases include:

* Returns and exchanges
* Shipping
* Shipping fees
* Payment methods
* Product usage
* Store information
* Store-specific FAQs

---

# Scenario Management

Specific questions can be answered with predefined responses without invoking an AI model.

Example:

```text
Keyword:
return

Match:
contains

Response:
Returns are accepted within seven days of delivery
for unopened products.
```

Supported matching methods:

| Type               | Behavior                         |
| ------------------ | -------------------------------- |
| Exact              | Input must match exactly         |
| Contains           | Input contains the keyword       |
| Regular expression | Match using a regular expression |

When multiple scenarios match, priority determines which response is returned.

---

# Chat History & Analytics

Customer and AI conversations can be reviewed from the administration panel.

Recorded information includes:

* User input
* AI response
* Session
* AI provider
* AI model
* Response time
* Token usage
* Errors
* Tools used
* Human response requests

Conversation history can be used to identify recurring questions and improve the knowledge base and scenarios.

```text
Customer questions
       │
       ▼
Conversation history
       │
       ▼
Identify recurring questions
       │
       ▼
Add knowledge / scenarios
       │
       ▼
Improve answer quality
```

---

# Statistics & Reports

AI chat usage can be analyzed by:

* AI provider
* AI model
* Time period
* Error status
* Response time
* Overall usage

CSV export is also supported.

---

# Access Control

Access rules can be configured to reduce unnecessary requests and abuse of AI APIs.

Supported rules include:

* IP address
* Time range
* Blocked words

Rate limiting is also applied to the MCP HTTP endpoint.

---

# Notifications

Notifications can be configured based on support conditions.

Supported channels include:

* Email
* Webhook
* LINE

This can be used to route conversations requiring human attention to store operations.

---

# Security

The plugin includes multiple security measures designed for AI functionality operating within an e-commerce environment.

These include:

* CSRF protection
* Masked API key display
* Log anonymization
* Scenario input validation
* Regular-expression injection protection
* Session-based rate limiting
* Per-IP MCP rate limiting
* Tool-specific MCP rate limiting
* JSON-RPC 2.0 validation
* Content-Type validation
* SQL wildcard escaping
* Internal error sanitization

MCP error responses are sanitized to prevent internal information such as SQLSTATE messages, Doctrine details, internal database table names, and PHP file paths from being exposed externally.

---

# Testing & Code Quality

The MCP HTTP interface is covered by Playwright E2E tests.

Test coverage includes areas such as:

* MCP Discovery
* `initialize`
* `tools/list`
* `tools/call`
* HTTP request validation
* Error handling
* Rate limiting
* Security-related responses

The PHP codebase uses the following quality tools:

* PHPUnit
* PHP_CodeSniffer
* PHPStan
* PHPMD
* PHPMetrics

Run code quality checks with:

```bash
composer quality
```

For CI:

```bash
composer quality:ci
```

---

# Requirements

## EC-CUBE

* EC-CUBE 4.2
* EC-CUBE 4.3

## PHP

```text
PHP >= 8.1
```

## Databases

* MySQL
* PostgreSQL
* SQLite (primarily for development and testing)

Compatibility considerations include DBMS-specific differences such as `LIKE` search escaping and boolean handling between MySQL and PostgreSQL.

---

# Architecture

AI Chat Assistant provides multiple ways for humans and AI agents to interact with EC-CUBE.

```text
                       EC-CUBE
                          │
               ┌──────────┴──────────┐
               │                     │
        Product Catalog         Store Knowledge
               │                     │
               └──────────┬──────────┘
                          │
                          ▼
                 AI Chat Assistant
                          │
        ┌─────────────────┼─────────────────┐
        │                 │                 │
        ▼                 ▼                 ▼
     OpenAI           Anthropic          Gemini


        ┌─────────────────────────────────┐
        │        AI Access Layer          │
        └─────────────────────────────────┘

              │             │
      ┌───────┴───────┐     └──────────────┐
      ▼               ▼                    ▼

 Chat Widget      MCP Server             WebMCP
      │               │                    │
      ▼               ▼                    ▼
  Customer       External AI Agent     Browser AI
```

In other words:

```text
Human → Chat → AI → EC-CUBE

AI Agent → MCP → EC-CUBE

Browser AI → WebMCP → EC-CUBE
```

These interaction models are provided within a single EC-CUBE plugin.

---

# Development

Clone the repository:

```bash
git clone https://github.com/routeflags/ec-cube-ai-chat-assistant42.git
cd ec-cube-ai-chat-assistant42
```

Install dependencies:

```bash
composer install
```

Run quality checks:

```bash
composer quality
```

For CI:

```bash
composer quality:ci
```

---

# Project Structure

```text
AiChatAssistant42/
├── Controller/
├── Entity/
├── Event/
├── Form/
├── Repository/
├── Resource/
├── Service/
├── Tests/
├── Documents/
├── composer.json
└── README.md
```

---

# Changelog

For release history, bug fixes, security improvements, and newly added features, see:

[CHANGELOG](Documents/CHANGELOG.md)

---

# Bug Reports & Feature Requests

Bug reports and feature requests are welcome through GitHub Issues:

https://github.com/routeflags/ec-cube-ai-chat-assistant42/issues

When reporting a bug, please include the following information where possible:

* EC-CUBE version
* PHP version
* Database
* Plugin version
* Steps to reproduce
* Expected behavior
* Actual behavior
* Error logs, excluding sensitive information

---

# Contributing

Issues, bug reports, documentation improvements, and pull requests are welcome.

For substantial changes, please open an Issue to discuss the proposal before implementation.

For pull requests, please consider:

* Consistency with the existing code style
* Adding or updating tests
* Static analysis
* Potential impact on existing functionality

---


# License

This project is released under the **GPL-2.0-only** license.

See the license file in this repository for details.

---

# Developed by

**ROUTE FLAGS Co., Ltd.**

GitHub:

https://github.com/routeflags

Website:

https://blog.routeflags.com/

---

# AI × Commerce × MCP × WebMCP

AI Chat Assistant is more than a chatbot added to an online store.

By connecting EC-CUBE product data, store-specific knowledge, AI models, MCP, and WebMCP, the project brings together:

```text
EC-CUBE
   +
Commerce Data
   +
AI
   +
MCP
   +
WebMCP
```

in a single open-source plugin.

The goal is to move beyond e-commerce where **humans ask AI about products**, toward commerce infrastructure where **AI agents themselves can discover products and interact with store capabilities**.

**Bringing EC-CUBE into the era of agentic commerce.**
