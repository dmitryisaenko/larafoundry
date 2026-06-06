# Tickets Module - Detailed Documentation

> **⚠️ Early design note (June 2025).** This page is an early planning draft from before the package was built. The shipped package differs in places: it dropped the `coderflex/laravel-ticket` dependency, moved categories and labels to config-driven JSON (no tables), and renamed the config and events. Treat this page as design intent, not current reference. For what the package actually ships, see the accurate reference at [../tickets.md](../tickets.md). This file stays at its original URL so older links keep working.

## Overview

LaraFoundry's ticket module provides a complete support ticket system with separate interfaces for users and admins, automatic status workflow, smart sorting, and category/label management.

## Features

| Feature | Description |
|---------|-------------|
| Dual Interface | Separate user and admin controllers, views, and policies |
| UUID URLs | Users access tickets via UUID, admins via integer ID |
| Auto Status | Status changes automatically based on who acts (user/admin) |
| Smart Sorting | 5-level admin sort: resolved → new → priority → status → date |
| Stats Dashboard | Open, high priority, standard priority, total counts |
| Category Toggle | Attach/detach categories without editing ticket |
| Label Toggle | Attach/detach labels without editing ticket |
| Auto-Hide | Resolved tickets disappear from user view after 7 days |
| Auto-Reopen | User reply to resolved ticket reopens it |
| Events | TicketCreate + TicketAnswerCreate for audit trail |
| Grid/List View | Admin can toggle between display modes |
| Search | Full-text search across title and message |

## Database Schema

### Tables

```
tickets
├── id (PK)
├── uuid (unique)
├── user_id (FK → users)
├── title (string)
├── message (text)
├── priority (enum: LOW, STANDARD, HIGH)
├── status (enum: wait-customer, wait-moderator, resolved)
├── is_resolved (boolean)
├── is_locked (boolean)
├── assigned_to (FK → users, nullable)
└── timestamps

ticket_categories
├── id, name, slug, is_visible, timestamps
└── Seeded: general, billing, feature request, bug report

ticket_labels
├── id, name, slug, is_visible, timestamps
└── Seeded: quick, complex

ticket_category_assignments (pivot)
├── ticket_id, category_id

ticket_label_assignments (pivot)
├── ticket_id, label_id

ticket_message_assignments (conversation)
├── id, user_id, ticket_id, message, timestamps
```

## Status Workflow

```
User creates ticket    → wait-moderator
Admin replies          → wait-customer
User replies           → wait-moderator
Admin closes           → resolved
User replies to resolved → wait-moderator (auto-reopen)
```

Status is never set manually by users. The system infers intent from the action performed.

## Admin API

| Method | Route | Action |
|--------|-------|--------|
| GET | /admin/tickets | Index with stats + filters |
| GET | /admin/tickets/create/{user} | Create form for user |
| POST | /admin/tickets | Store ticket |
| GET | /admin/tickets/{ticket} | Show with conversation |
| GET | /admin/tickets/{ticket}/edit | Edit form |
| PUT | /admin/tickets/{ticket} | Update ticket |
| POST | /admin/tickets/{ticket}/reply | Admin reply |
| PATCH | /admin/tickets/{ticket}/close | Close ticket |
| PATCH | /admin/tickets/{ticket}/priority | Update priority |
| POST | /admin/tickets/{ticket}/category/toggle | Toggle category |
| POST | /admin/tickets/{ticket}/label/toggle | Toggle label |

## User API

| Method | Route | Action |
|--------|-------|--------|
| GET | /tickets | List own tickets |
| GET | /tickets/create | Create form |
| POST | /tickets | Store ticket |
| GET | /tickets/{ticket:uuid} | Show ticket |
| POST | /tickets/{ticket:uuid}/messages | Add reply |

## Smart Sorting (Admin)

Multi-level sort applied in `AdminTicketsFilter`:

1. Unresolved tickets first (resolved at bottom)
2. New tickets (never touched: created_at = updated_at)
3. High priority before standard
4. Waiting for moderator before waiting for customer
5. Most recently updated first

## Filtering

- **search** - text search in title and message fields
- **status** - filter by wait-customer, wait-moderator, resolved
- **priority** - filter by standard, high
- **show_tickets** - quick filter: standard_priority, high_priority

## Authorization (TicketPolicy)

| Action | User | Admin |
|--------|------|-------|
| View list | Own tickets only | All tickets |
| View single | Own + not resolved + not old resolved | Any ticket |
| Create | Yes | Yes (on behalf of user) |
| Update | Own + not resolved | Any ticket |
| Reply | Own ticket | Any ticket |
| Delete | Never | N/A |
| Close | Never | Yes |

## Events

### TicketCreate
- Trigger: User creates a new ticket
- Data: user, ticket (id, title, message, priority, categories)

### TicketAnswerCreate
- Trigger: Message added to a ticket
- Data: user, ticket, message content

## Validation

### User TicketStoreRequest
- title: required, string, max 255
- message: required, string, min 10
- priority: required, in: high, standard
- categories: required, array, values exist in ticket_categories.slug

### Admin TicketStoreRequest
- title: required, string, max 255
- message: required, string, max 5000
- priority: sometimes, in: standard, high
- categories: array (optional)
- labels: array (optional)
- user: exists in users table

### Admin TicketUpdateRequest
- Authorization: is_agent() check
- title, message, priority, status, categories, labels

### StoreTicketMessageRequest
- message: required, string, min 5

## Frontend Components

### Admin
- **IndexTickets.vue** - Stats cards, filter form, grid/list toggle
- **CreateTicket.vue** - User info + ticket form
- **ShowTicket.vue** - Category/label toggles, priority selector, conversation, reply
- **EditTicket.vue** - Full edit form with status selector
- **TicketCard.vue** - Grid view component with badges, preview, actions
- **TicketListItem.vue** - List view component with full metadata
- **TicketForm.vue** - Reusable form component

### User
- **IndexTickets.vue** - Ticket list with status badges
- **CreateTicket.vue** - Title, message, priority, categories form
- **ShowTicket.vue** - Conversation thread with reply

### Shared
- **MessageList.vue** - Conversation thread (used in both admin and user show pages)

## Configuration

From `config/laravel_ticket.php`:
- Categories: general, billing, feature request, bug report
- Labels: quick, complex
- Priorities: standard, high
- Statuses: wait-customer, wait-moderator, resolved

## File Structure

```
app/
├── Models/Ticket.php
├── Http/
│   ├── Controllers/
│   │   ├── TicketController.php (user)
│   │   └── Admin/TicketController.php
│   ├── Requests/
│   │   ├── TicketStoreRequest.php
│   │   ├── StoreTicketMessageRequest.php
│   │   └── Admin/
│   │       ├── TicketStoreRequest.php
│   │       └── TicketUpdateRequest.php
│   ├── Resources/
│   │   ├── TicketResource.php
│   │   └── Admin/TicketResource.php
│   └── Filters/AdminTicketsFilter.php
├── Actions/GetTicketsStat.php
├── Events/Tickets/
│   ├── TicketCreate.php
│   └── TicketAnswerCreate.php
└── Policies/TicketPolicy.php

resources/js/pages/
├── tickets/
│   ├── IndexTickets.vue
│   ├── CreateTicket.vue
│   └── ShowTicket.vue
└── admin/
    ├── tickets/
    │   ├── IndexTickets.vue
    │   ├── CreateTicket.vue
    │   ├── ShowTicket.vue
    │   └── EditTicket.vue
    └── components/tickets/
        ├── TicketCard.vue
        ├── TicketListItem.vue
        ├── TicketForm.vue
        └── MessageList.vue

config/laravel_ticket.php
database/migrations/2025_10_01_*
database/factories/TicketFactory.php
database/seeders/TicketSeeder.php
```

## Testing

- Feature tests: user CRUD, admin CRUD, authorization, filtering
- Unit tests: model relationships, scopes, computed attributes
- Policy tests: view, update, reply, delete permissions

## Dependencies

- `coderflex/laravel-ticket` - base package (extended with custom model, filters, events)
