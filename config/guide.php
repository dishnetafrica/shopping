<?php

/*
 * CloudBSS v3 Training Manual — content only (the GuideCapability renders this).
 * Edit wording here freely; no code changes needed. Each section:
 *   'menu'  => the menu key that unlocks it (null = everyone sees it)
 *   'cards' => dl cards: ['title', rows: [step => instruction]]
 *   'links' => workspaces to open from the section: [key => label]
 * Keys must be v3 capability keys (the suite verifies them).
 */
return [

    ['key' => 'start', 'title' => 'Getting started', 'menu' => null,
        'cards' => [
            ['title' => 'Your screen in 30 seconds', 'rows' => [
                ['Sidebar', 'Everything you can do, grouped: Sales, Messaging, Inventory, Finance. Numbers on items = things needing attention (unread chats, open quotations).'],
                ['Search bar', 'Type a customer name, order number, or phone to jump straight to it — e.g. "customer 5".'],
                ['Quick actions', 'Green buttons on every screen. They open a small form, you fill it, done. All data saves to the same system as before.'],
                ['Refresh', 'Screens refresh themselves; the circle icon at the top forces it.'],
            ]],
            ['title' => 'Every morning', 'rows' => [
                ['1', 'Open Morning Briefing — yesterday\'s numbers and today\'s priorities in one page.'],
                ['2', 'Open My Work — your personal task list. Work top to bottom.'],
                ['3', 'Check Chats for the unread badge — customers who wrote overnight.'],
            ]],
            ['title' => 'If you are stuck', 'rows' => [
                ['Old screen', 'The classic panel still opens: account menu → "Open classic panel" (or add ?classic=1 to the address).'],
                ['Wrong menu?', 'You only see screens your role allows. If something is missing, ask the owner to check your role in Settings → Team.'],
            ]],
        ],
        'links' => ['briefing' => 'Open Morning Briefing', 'worklist' => 'Open My Work'],
    ],

    ['key' => 'sales', 'title' => 'Sales & orders', 'menu' => 'orders',
        'cards' => [
            ['title' => 'Order to money — the full loop', 'rows' => [
                ['1 · Order', 'Sales Orders shows every order with its margin. Open one to see items, costs, and payments together.'],
                ['2 · Costs', 'Every shilling spent on an order (freight, boda, purchase) is recorded against it — that is how the margin stays true.'],
                ['3 · Invoice', 'Invoices are issued from the order. Share by WhatsApp or email from the screen.'],
                ['4 · Payment', 'Record payments as they arrive; Collections shows who still owes.'],
            ]],
            ['title' => 'Scheduled orders', 'rows' => [
                ['Park it', 'Customer wants delivery Thursday 2pm? Scheduled → "Schedule an order" with the date and time. It waits there, out of the daily flow.'],
                ['Automatic', 'The system moves it back into the normal flow at the right time and handles the reminders — nothing to remember.'],
                ['Change of plan', '"Change a delivery time" or "Send now" releases it immediately.'],
            ]],
            ['title' => 'Quotations', 'rows' => [
                ['Create', 'Quotations → New. Add items, send to the customer by WhatsApp.'],
                ['Convert', 'When the customer agrees, one click converts the quotation into a sales order — nothing retyped.'],
                ['Follow up', 'The Quotations badge counts open ones. Old open quotations = money waiting for a phone call.'],
            ]],
        ],
        'links' => ['salesOrders' => 'Open Sales Orders', 'quotations' => 'Open Quotations', 'collections' => 'Open Collections'],
    ],

    ['key' => 'purchasing', 'title' => 'Purchasing (buyer flow)', 'menu' => 'purchases',
        'cards' => [
            ['title' => 'The buyer\'s day', 'rows' => [
                ['1 · What to buy', 'Procurement Hub and Demand Plans show what the shop needs. The buy list comes from real orders and stock levels.'],
                ['2 · Record purchase', 'Purchasing → "Record purchase" the moment you pay a supplier: amount, currency, cash or credit. Attach the order number when it is for a specific order.'],
                ['3 · Approval', 'Staff-recorded costs show as Pending until the owner/manager approves — you will see the status change; nothing else to do.'],
                ['4 · Supplier debt', 'Credit purchases appear under Suppliers → still owed. Check before promising payment dates.'],
            ]],
            ['title' => 'For approvers (owner / manager / accountant)', 'rows' => [
                ['Where', 'Purchasing shows an "Awaiting approval" number when costs are pending.'],
                ['How', 'Quick actions list each pending cost with Approve / Reject. One tap, with the recorder\'s name and amount shown.'],
                ['Why it matters', 'Approval is what moves the cost into the order\'s real margin and the P&L.'],
            ]],
        ],
        'links' => ['purchase.process' => 'Open Purchasing', 'procurement.hub' => 'Open Procurement Hub', 'suppliers' => 'Open Suppliers'],
    ],

    ['key' => 'cash', 'title' => 'Cash & money', 'menu' => 'cash',
        'cards' => [
            ['title' => 'Cashbook — three currencies, one truth', 'rows' => [
                ['Record', 'Every cash in/out goes in the Cashbook the moment it happens: USD, UGX, or SSP.'],
                ['Convert', 'Changed money at the forex? Use Convert — it records both sides with the rate, so nothing "disappears".'],
                ['Transfer', 'Moving cash between people or tills? Use Transfer — both hands are recorded.'],
                ['Rule', 'If cash moved and the Cashbook does not show it, it did not happen. Record first, move second.'],
            ]],
        ],
        'links' => ['cashbook' => 'Open Cashbook'],
    ],

    ['key' => 'finance', 'title' => 'Finance & expenses', 'menu' => 'finance',
        'cards' => [
            ['title' => 'Recording expenses', 'rows' => [
                ['Overhead', 'Finance → "Record expense" with the order number left BLANK = general overhead (rent, salaries, airtime).'],
                ['Order cost', 'Type an order number = the expense books into that order\'s margin instead. Two orders? Comma-separate — it splits evenly.'],
                ['Rate', 'Paying in UGX/SSP? Enter the rate to USD so reports stay in one currency. Paying USD? Rate = 1.'],
            ]],
            ['title' => 'Reading the numbers', 'rows' => [
                ['Cash on hand', 'All currencies converted to USD — what you could touch today.'],
                ['Receivable / Payable', 'What customers owe you / what you owe suppliers.'],
                ['Runway', 'How many days the cash lasts at current burn. Under 14 days shows a warning.'],
            ]],
        ],
        'links' => ['finance' => 'Open Finance', 'payments' => 'Open Payments'],
    ],

    ['key' => 'chats', 'title' => 'WhatsApp chats', 'menu' => 'whatsapp',
        'cards' => [
            ['title' => 'Bot and human, working together', 'rows' => [
                ['Bot mode', 'The bot answers customers automatically: prices, orders, catalogue. Most chats never need you.'],
                ['Take over', 'Complicated customer? Open the chat → "Take over". The bot goes quiet and you type as yourself.'],
                ['Reply', 'Replying from a chat automatically takes it over — the customer never gets a bot answer on top of yours.'],
                ['Hand back', 'Finished? "Hand back to bot" — it resumes answering that customer.'],
                ['Unread badge', 'The sidebar number = conversations waiting for a human. Aim for zero before lunch.'],
            ]],
        ],
        'links' => ['chats' => 'Open Chats'],
    ],

    ['key' => 'email', 'title' => 'Email Center (admin)', 'menu' => 'settings',
        'cards' => [
            ['title' => 'One-time setup', 'rows' => [
                ['1 · Provider', 'Providers → Add provider (your SMTP or Mailgun/SendGrid/Postmark/Brevo) → press its Test button — a test email must arrive.'],
                ['2 · Sender', '"Sender & go-live" → From name + From email. Keep live = 0 while testing.'],
                ['3 · Routing', '"Edit routing" → choose per event (invoice, receipt, OTP…) whether it goes by email, WhatsApp, or both.'],
                ['4 · Go live', 'When the Queue shows clean sends for a few days, set live = 1. The gate refuses if From or a working provider is missing.'],
            ]],
            ['title' => 'Daily reading', 'rows' => [
                ['Queue', 'Every message with its status. Failed ones can be retried from Quick actions.'],
                ['Suppressions', 'Addresses that bounced or unsubscribed — the system will never email them again. You can add or release addresses manually.'],
                ['Failed badge', 'The sidebar number on Email Center = failed messages needing a look.'],
            ]],
        ],
        'links' => ['communication' => 'Open Email Center'],
    ],

    ['key' => 'stock', 'title' => 'Inventory & warehouse', 'menu' => 'products',
        'cards' => [
            ['title' => 'Stock basics', 'rows' => [
                ['Products', 'The catalogue: prices, photos, categories. What the bot quotes and the storefront shows.'],
                ['Warehouse', 'Counts and movements. Count what is on the shelf; the system shows the difference.'],
                ['Juba stock', 'Stock position on the Juba side, kept separate from Kampala.'],
            ]],
        ],
        'links' => ['products' => 'Open Products', 'warehouse' => 'Open Warehouse'],
    ],

    ['key' => 'consignments', 'title' => 'Consignments (Juba loads)', 'menu' => 'orders',
        'cards' => [
            ['title' => 'Sending a load (Kampala side)', 'rows' => [
                ['1 · Create', 'Consignments → "New consignment": bus, driver, and the receiving agent. If the last load had unsent stock, it carries forward automatically.'],
                ['2 · Load orders', '"Add order" for one, or "Add ALL ready orders" in one tap. The manifest shows every customer and phone.'],
                ['3 · Load stock', '"Add stock line" for shop goods travelling with the load — request first, mark quantities sent when the bus is packed.'],
                ['4 · Print', '"Manifest sheet" prints the full load list for the driver.'],
            ]],
            ['title' => 'Receiving a load (Juba side)', 'rows' => [
                ['Everything arrived', '"Deliver ALL on-board" — one tap, every customer gets their WhatsApp automatically.'],
                ['One order at a time', '"Set one order\'s status" — delivered or returned, with a note.'],
                ['Selling carried stock', '"Sell from stock" records the sale with buyer and price; margin appears on the Stock tab instantly.'],
            ]],
        ],
        'links' => ['consignments' => 'Open Consignments'],
    ],

    ['key' => 'admin', 'title' => 'Settings & team (owner)', 'menu' => 'staff',
        'cards' => [
            ['title' => 'Team & roles — who sees what', 'rows' => [
                ['Owner / Manager', 'Everything.'],
                ['Accountant', 'Orders, customers, money screens, approvals.'],
                ['Purchaser / Field', 'Orders, purchasing, cashbook — the buying loop.'],
                ['Sales', 'Orders, quotations, customers.'],
                ['Change a role', 'Settings → Team. Takes effect on the person\'s next page load.'],
            ]],
            ['title' => 'Business settings', 'rows' => [
                ['Profile', 'Settings → Business profile: name, currency, invoice details — what appears on documents.'],
                ['WhatsApp', 'Settings → WhatsApp & bot: connection status and bot behaviour.'],
            ]],
        ],
        'links' => ['settings' => 'Open Settings'],
    ],
];
