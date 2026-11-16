const CFG = { base:'/api/v1/workspace', csrf: document.querySelector('meta[name=csrf-token]').content, user: {"name":""} };
const LABELS = { pos:'POS Till', 'ai.campaign':'AI Campaign', advisor:'Solar Advisor', setup:'Get started', chats:'Chats', chat:'Chat', communication:'Email Center', guide:'Help & Training', consignments:'Consignments', consignment:'Consignment', scheduled:'Scheduled', briefing:'Today', worklist:'My Work', tasks:'Tasks', followups:'AI Follow-ups', diagnostics:'Bot Diagnostics', customers:'Customers', leads:'Leads', growth:'Growth', demand:'Asked for', discovery:'Businesses found', visits:'Visits', collections:'Collections',
  salesOrders:'Sales Orders', quotations:'Quotations', invoices:'Invoices', payments:'Payments', finance:'Finance', pnl:'Profit & Loss', books:'Books', partners:'Partners',
  products:'Products', categories:'Categories', 'purchase.process':'Purchasing', 'procurement.hub':'Procurement Hub', 'demand.plans':'Demand Plans', 'juba.stock':'Juba Stock', suppliers:'Suppliers', lpos:'Purchase Orders', warehouse:'Warehouse', dispatch:'Dispatch', transport:'Transport Register', cashbook:'Cashbook',
  customer:'Customer', invoice:'Invoice', payment:'Payment', product:'Product', supplier:'Supplier', quotation:'Quotation',
  salesOrder:'Sales Order', purchaseOrder:'Purchase Order', lpo:'Purchase Order', 'sales.process':'Sales Pipeline', statement:'Statement', settings:'Settings', ads:'Facebook Ads', campaigns:'Campaigns', 'storefront.insights':'Storefront Insights',
  jobs:'Jobs', workOrder:'Job', maintenance:'Maintenance', maintenancePlan:'Service Plan', claims:'Warranty Claims', warrantyClaim:'Claim', trips:'Trips', trip:'Trip', salesBrain:'Sales Brain', salesKnowledge:'Sales knowledge', modules:'Modules', billing:'Billing', team:'Team & Roles',
  'storefront.collections':'Shop for Every Need', 'storefront.kits':'Ready-made Kits', 'storefront.delivery':'Delivery & Free Shipping',
  tenders:'Tenders', suggestions:'Suggestions', evidence:'Evidence', engagement:'Customer Engagement', 'vision.review':'Stock & Loss' };
// Navigation IA (2026-08) — grouped around the operator's question in the moment (Am I selling? buying?
// counting stock? handling money?), each screen under exactly one group. Group HEADERS live here; item
// LABELS live in LABELS above; has() gates by key regardless of group, so moving a key between groups
// never changes what a role can see. Renames/order only — no routes, permissions or keys change.
const GROUPS = [ ['Home',['briefing','worklist','setup','guide']],
  ['Sell',['pos','salesOrders','quotations','invoices','payments','collections','advisor']],
  ['Customers',['customers','leads','trips','salesBrain','followups','growth','discovery','visits','demand','tenders','evidence']],
  ['Buy',['purchase.process','suppliers','lpos','procurement.hub','demand.plans','juba.stock']],
  ['Stock',['products','categories','suggestions','warehouse','consignments','vision.review']],
  ['Money',['finance','cashbook','partners']],
  ['Communication',['chats','communication','campaigns','engagement','ai.campaign','ads','diagnostics']],
  ['Operations',['jobs','maintenance','claims','tasks','dispatch','transport','scheduled']],
  ['Reports',['pnl','books','storefront.insights']],
  ['Settings',['settings','team','modules','billing','storefront.collections','storefront.kits','storefront.delivery']] ];
// Which nav key reads which count from the catalog's counts map.
const NAVCOUNT = { growth:'growth', demand:'demand', discovery:'discovery', visits:'visits', suggestions:'suggestions', followups:'followups', diagnostics:'diagnostics', chats:'chats', communication:'communication', consignments:'consignments', scheduled:'scheduled', customers:'customers', salesOrders:'salesOrder', quotations:'quotations', invoices:'invoice', payments:'payment', products:'product', categories:'categories', suppliers:'supplier', 'purchase.process':'purchase.process' };
// DOCUMENTATION ONLY — read by nothing. What actually gates a menu item is has() below,
// via the permission keys the server sends. Left here as a list of what has been built,
// and labelled because four test contracts were written against it as though it were a gate.
const READY = new Set(['pos','ai.campaign','advisor','setup','briefing','worklist','followups','diagnostics','chats','communication','guide','consignments','scheduled','customers','growth','discovery','visits','leads','trips','salesBrain','salesOrders','quotations','invoices','payments','products','categories','suppliers','purchase.process','demand.plans','procurement.hub','juba.stock','collections','finance','pnl','books','warehouse','dispatch','tasks','settings','cashbook','partners','ads','campaigns','storefront.insights','storefront.collections','storefront.kits','storefront.delivery','transport','jobs','maintenance','claims','modules','billing','team','tenders','suggestions','evidence','engagement','vision.review']);
// Storefront config pages are client-rendered (not server workspaces) and always available to the owner.
const SF_KEYS = new Set(['storefront.collections','storefront.kits','storefront.delivery','transport']);
// Dedicated list views still pending — none; kept for future additions.
const SOON = {};
function fmtCount(n){ n=Number(n); return n>=1000 ? (n/1000).toFixed(n>=10000?0:1)+'k' : String(n); }

/* ---- inline icon set ---- */
const P = {
  pos:'M4 3h16v9H4zM8 12v3M16 12v3M2 15h20v6H2zM6 18h5',
  'ai.campaign':'M12 3l1.8 4.6L18 9.4l-4.2 1.8L12 16l-1.8-4.8L6 9.4l4.2-1.8zM19 15l.9 2.3 2.1.9-2.1.9L19 21l-.9-1.9-2.1-.9 2.1-.9zM5 15l.9 2.3 2.1.9-2.1.9L5 21l-.9-1.9-2.1-.9 2.1-.9z',
  advisor:'M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1l2.1-2.1M17 7l2.1-2.1M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z',
  scheduled:'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2',
  jobs:'M9 3h6a1 1 0 0 1 1 1v2h3a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h3V4a1 1 0 0 1 1-1zM9 11l2 2 4-4',
  workOrder:'M9 3h6a1 1 0 0 1 1 1v2h3a1 1 0 0 1 1 1v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h3V4a1 1 0 0 1 1-1zM9 11l2 2 4-4',
  maintenance:'M14.7 6.3a4 4 0 0 1-5.2 5.2l-6 6a1.8 1.8 0 0 0 2.5 2.5l6-6a4 4 0 0 1 5.2-5.2l-2.6 2.6-2-2 2.6-2.6z',
  maintenancePlan:'M14.7 6.3a4 4 0 0 1-5.2 5.2l-6 6a1.8 1.8 0 0 0 2.5 2.5l6-6a4 4 0 0 1 5.2-5.2l-2.6 2.6-2-2 2.6-2.6z',
  claims:'M12 2 4 5v6c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V5l-8-3zM9.5 12l2 2 4-4',
  trips:'M2.5 13.5l19-8-5.5 14-3.6-5.4-4 3.4.4-4.6 8.2-6.2',
  trip:'M2.5 13.5l19-8-5.5 14-3.6-5.4-4 3.4.4-4.6 8.2-6.2',
  salesBrain:'M4 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H4zM20 4h-7a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h8z',
  salesKnowledge:'M4 4h7a3 3 0 0 1 3 3v13a2 2 0 0 0-2-2H4zM20 4h-7a3 3 0 0 0-3 3v13a2 2 0 0 1 2-2h8z',
  warrantyClaim:'M12 2 4 5v6c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V5l-8-3zM9.5 12l2 2 4-4',
  shield:'M12 2 4 5v6c0 5 3.4 8.5 8 10 4.6-1.5 8-5 8-10V5l-8-3z',
  modules:'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
  billing:'M2 7h20v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2zM2 10h20M6 15h4',
  team:'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
  tenders:'M8 3h8v5a4 4 0 0 1-8 0zM8 5H5v1a3 3 0 0 0 3 3M16 5h3v1a3 3 0 0 1-3 3M12 12v4M8.5 20h7l-.8-2.5H9.3z',
  suggestions:'M9 21h6M10 18h4M12 3a6 6 0 0 0-3.5 10.9c.6.5.9 1.2 1 1.9h5c.1-.7.4-1.4 1-1.9A6 6 0 0 0 12 3z',
  evidence:'M7 3h7l5 5v13H7zM14 3v5h5M9.5 13l2 2 3.5-4',
  consignments:'M1 3h15v13H1zM16 8h4l3 3v5h-7zM5.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5zM18.5 21a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5z',
  consignment:'M1 3h15v13H1zM16 8h4l3 3v5h-7z',
  guide:'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M4 19.5A2.5 2.5 0 0 0 6.5 22H20V2H6.5A2.5 2.5 0 0 0 4 4.5v15z',
  communication:'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 6l-10 7L2 6',
  chats:'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
  chat:'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
  setup:'M9 11l3 3L22 4M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11',
  briefing:'M12 3v18M3 12h18', worklist:'M9 6h11M9 12h11M9 18h11M4 6h.01M4 12h.01M4 18h.01', tasks:'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4',
  settings:'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z',
  collections:'M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
  finance:'M3 3v18h18M7 14l4-4 3 3 5-6', 'sales.process':'M3 3v18h18M18 9l-6 6-4-4-3 3',
  cashbook:'M2 7a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2zM2 10h20M16 15h2',
  'purchase.process':'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
  'demand.plans':'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 14l2 2 4-4',
  'procurement.hub':'M3 3v18h18M8 17V9M13 17V5M18 17v-6',
  lpos:'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 12h6M9 16h4',
  lpo:'M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2M9 12h6M9 16h4',
  'juba.stock':'M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16zM3.27 6.96 12 12.01l8.73-5.05M12 22.08V12',
  warehouse:'M3 9l9-6 9 6v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM9 21V9h6v12',
  dispatch:'M1 3h15v13H1zM16 8h4l3 3v5h-7M5.5 18.5a2 2 0 1 0 0 .01M18.5 18.5a2 2 0 1 0 0 .01',
  customer:'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
  customers:'M17 21v-2a4 4 0 0 0-3-3.87M9 21v-2a4 4 0 0 1 4-4h0M12 7a3 3 0 1 0 0-6 3 3 0 0 0 0 6M21 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
  invoice:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 13h6M9 17h6',
  invoices:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 13h6M9 17h6',
  quotations:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 15l2 2 4-4',
  quotation:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M9 15l2 2 4-4',
  payment:'M2 6h20v12H2zM2 10h20', payments:'M2 6h20v12H2zM2 10h20',
  supplier:'M3 21h18M5 21V7l8-4v18M19 21V11l-6-3M9 9v.01M9 12v.01M9 15v.01',
  suppliers:'M3 21h18M5 21V7l8-4v18M19 21V11l-6-3M9 9v.01M9 12v.01M9 15v.01',
  product:'M12 2 2 7l10 5 10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
  products:'M12 2 2 7l10 5 10-5zM2 17l10 5 10-5M2 12l10 5 10-5',
  categories:'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
  ads:'M11 5 6 9H2v6h4l5 4V5zM19 12a7 7 0 0 0-2-4.9M15.5 8.5a3 3 0 0 1 0 5',
  demand:'M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z', discovery:'M21 21l-4.35-4.35M11 19a8 8 0 1 1 0-16 8 8 0 0 1 0 16z', visits:'M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0zM12 7a3 3 0 1 0 0 6 3 3 0 0 0 0-6z', growth:'M23 6l-9.5 9.5-5-5L1 18M17 6h6v6', leads:'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 7a4 4 0 1 0 0 8 4 4 0 0 0 0-8M19 8v6M22 11h-6',
  campaigns:'M22 2 11 13M22 2l-7 20-4-9-9-4z',
  engagement:'M12 3a9 9 0 1 0 0 18 9 9 0 0 0 0-18zM12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8zM12 11a1 1 0 1 0 0 2 1 1 0 0 0 0-2z',
  'vision.review':'M2 7h3l2-2h6l2 2h3a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1zM12 10a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7z',
  'storefront.insights':'M3 3v18h18M7 15l3-4 3 3 4-6',
  'storefront.collections':'M3 9l1-5h16l1 5M3 9h18v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM9 9a3 3 0 0 0 6 0',
  'storefront.kits':'M20 12v9H4v-9M2 7h20v5H2zM12 22V7M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7zM12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z',
  'storefront.delivery':'M1 3h15v13H1zM16 8h4l3 3v5h-7M5.5 18.5a2 2 0 1 0 0 .01M17.5 18.5a2 2 0 1 0 0 .01',
  'transport':'M1 3h15v13H1zM16 8h4l3 3v5h-7M5.5 18.5a2 2 0 1 0 0 .01M17.5 18.5a2 2 0 1 0 0 .01',
  salesOrder:'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
  salesOrders:'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
  purchaseOrder:'M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4zM3 6h18M16 10a4 4 0 0 1-8 0',
  statement:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6',
  doc:'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6',
  clock:'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM12 6v6l4 2', bolt:'M13 2 3 14h9l-1 8 10-12h-9z',
  check:'M20 6 9 17l-5-5', chev:'m9 18 6-6-6-6',
  trash:'M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6',
  ban:'M12 2a10 10 0 1 0 0 20 10 10 0 0 0 0-20zM5 5l14 14',
  trend:'M23 6l-9.5 9.5-5-5L1 18M17 6h6v6',
  book:'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z',
  partners:'M16 20v-1.5a3.5 3.5 0 0 0-3.5-3.5h-5A3.5 3.5 0 0 0 4 18.5V20M10 11.5a3.25 3.25 0 1 0 0-6.5 3.25 3.25 0 0 0 0 6.5M20 20v-1.5a3.5 3.5 0 0 0-2.6-3.4M15.5 5.2a3.25 3.25 0 0 1 0 6.1',
  books:'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2zM8.5 7h7M8.5 10.5h7M8.5 14h4',
  phone:'M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.94.36 1.86.7 2.73a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.35-1.35a2 2 0 0 1 2.11-.45c.87.34 1.79.57 2.73.7A2 2 0 0 1 22 16.92z',
  edit:'M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z',
  bell:'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0',
  card:'M3 4h18a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM1 10h22',
  plus:'M12 5v14M5 12h14',
  dots:'M12 5a1 1 0 100 2 1 1 0 100-2M12 11a1 1 0 100 2 1 1 0 100-2M12 17a1 1 0 100 2 1 1 0 100-2',
  tag:'M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z',
  mail:'M3 4h18a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 7l-10 6L2 7',
  x:'M18 6L6 18M6 6l12 12',
  cart:'M9 20a1 1 0 100 2 1 1 0 100-2M20 20a1 1 0 100 2 1 1 0 100-2M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6',
  pin:'M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0zM12 7a3 3 0 100 6 3 3 0 100-6',
  dollar:'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
  user:'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 3a4 4 0 100 8 4 4 0 100-8',
  spark:'M12 3l1.6 4.4L18 9l-4.4 1.6L12 15l-1.6-4.4L6 9z',
  send:'M22 2L11 13M22 2l-7 20-4-9-9-4z',
  download:'M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3',
  truck:'M1 3h15v10H1zM16 8h4l3 3v2h-7M3 18a2 2 0 104 0 2 2 0 10-4 0M15 18a2 2 0 104 0 2 2 0 10-4 0' };
function icon(name,cls){ return '<svg class="ic '+(cls||'')+'" viewBox="0 0 24 24"><path d="'+(P[name]||P.doc)+'"/></svg>'; }
const AVC = ['#16a34a','#0d9488','#0284c7','#4f46e5','#d97706','#db2777','#7c3aed','#0891b2'];
function avColor(s){ let h=0; for(const c of (s||'?')) h=(h*31+c.charCodeAt(0))>>>0; return AVC[h%AVC.length]; }
function initials(s){ s=(s||'?').replace(/[^\w ]/g,'').trim(); const p=s.split(/\s+/); return ((p[0]?.[0]||'')+(p[1]?.[0]||'')||s[0]||'?').toUpperCase(); }

let CUR = { key:null, id:null };
let REC_TAB = 'overview';   // active tab in the record shell
let LAST_REC = null;        // last record payload, so tab switches re-render without a refetch

// Append the active report-currency override (?cur=) so every workspace + command call is consistent.
function withCur(u){ const c=CFG.cur; if(!c) return u; return u+(u.indexOf('?')<0?'?':'&')+'cur='+encodeURIComponent(c); }
// v4.91 — CFG.csrf was read once, at page load, and never looked at again. Every write for the rest
// of the day used that one frozen token, so when the session rotated it hours later every save and
// every upload failed with a 419 the staff reported as "token missing". Refreshing the page always
// "fixed" it, because a refresh is the only thing that ever re-read the token — which is exactly why
// it looked random and unfixable from the floor.
//
// A write that meets a stale token now fetches a live one and retries itself, once. A dead SESSION is
// a different failure and must NOT be retried: that one says so plainly and goes to the login screen,
// instead of the silent nothing staff were getting.
let __csrfBusy = null;
async function refreshCsrf(){
  if(__csrfBusy) return __csrfBusy;      // one refresh in flight no matter how many writes queue behind it
  __csrfBusy = (async () => {
    try{
      const r = await fetch('/api/v1/workspace/_token', { credentials:'include',
        headers:{ 'Accept':'application/json','X-Requested-With':'XMLHttpRequest' } });
      if(r.status === 401) return null;   // the session is gone; a new token would not help
      const d = await r.json();
      if(d && d.token){
        CFG.csrf = d.token;
        const m = document.querySelector('meta[name=csrf-token]'); if(m) m.content = d.token;
        return d.token;
      }
    }catch(e){}
    return null;
  })();
  const out = await __csrfBusy; __csrfBusy = null; return out;
}
function sessionExpired(){
  toast('Your session ended — please sign in again', true);
  setTimeout(()=>{ location.href = '/app/login'; }, 1600);
}
async function api(path){
  const r = await fetch(withCur(CFG.base+path), { credentials:'include', headers:{ 'Accept':'application/json','X-Requested-With':'XMLHttpRequest' } });
  if(r.status === 401){ sessionExpired(); return { _error:401 }; }
  if(!r.ok && r.status!==200){ return { _error:r.status }; }
  return r.json();
}
async function post(endpoint, body){
  const send = () => fetch(withCur(endpoint), { method:'POST', credentials:'include',
    headers:{ 'Accept':'application/json','X-Requested-With':'XMLHttpRequest','X-CSRF-TOKEN':CFG.csrf,'Content-Type':'application/json' },
    body: JSON.stringify(body||{}) });
  let r = await send();
  if(r.status === 419){                  // stale token — get a live one and send it again, once only
    const t = await refreshCsrf();
    if(t) r = await send();
  }
  if(r.status === 401){ sessionExpired(); return { ok:false, error:'Your session ended — please sign in again.' }; }
  if(r.status === 419){ return { ok:false, error:'Could not verify that request. Please refresh the page and try once more.' }; }
  // A non-JSON body (an HTML error page, a proxy timeout) used to throw here and surface as nothing
  // at all. Say what happened instead.
  try{ return await r.json(); }
  catch(e){ return { ok:false, error:'The server did not send a readable reply (status ' + r.status + ').' }; }
}
// GET-with-query write, for reusing legacy panel endpoints that read $request->query() (e.g. /papi/lead-save).
// No body/JSON — every field goes on the query string, exactly like the panel does.
async function getQuery(endpoint, body){
  const parts=[]; Object.entries(body||{}).forEach(([k,v])=>{ if(v!=null && v!=='') parts.push(encodeURIComponent(k)+'='+encodeURIComponent(v)); });
  const url = endpoint + (parts.length ? ((endpoint.indexOf('?')<0?'?':'&')+parts.join('&')) : '');
  const r = await fetch(withCur(url), { credentials:'include', headers:{ 'Accept':'application/json','X-Requested-With':'XMLHttpRequest' } });
  return r.json();
}
function toast(m,err){ const t=document.createElement('div'); t.className='toast'+(err?' err':'');
  t.innerHTML=icon(err?'bolt':'check')+'<span>'+m+'</span>'; document.getElementById('toasts').appendChild(t);
  setTimeout(()=>{ t.style.opacity='0'; t.style.transition='.3s'; setTimeout(()=>t.remove(),300); },2600); }
function esc(s){ return String(s==null?'':s).replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
function toneCls(t){ return ['warn','danger','ok'].includes(t)?t:''; }
function toneBadge(t){ const m={danger:'b-danger',warn:'b-warn',ok:'b-ok',info:'b-info',accent:'b-accent'}; return m[t]||'b-default'; }
function statusBadge(s){ if(!s) return ''; const map={overdue:'b-danger',out:'b-danger',blocked:'b-danger',partial:'b-warn',low:'b-warn',draft:'b-default',pending:'b-warn',active:'b-ok',paid:'b-ok','in':'b-ok',received:'b-ok',delivered:'b-ok',inactive:'b-default',dormant:'b-warn',prospect:'b-info','new':'b-info'};
  return '<span class="badge '+(map[s]||'b-default')+'"><span class="d"></span>'+esc(s)+'</span>'; }
function apiToHash(p){ if(!p) return null; const i=p.indexOf('/workspace/'); if(i<0) return null; const parts=p.slice(i+11).split('/'); return { key:parts[0], id:parts[1]||null, action:parts[2]||null }; }
// Minor-unit precision, mirroring \App\Services\Pricing::decimalsForCurrency on the server:
// zero-decimal currencies (UGX/SSP + the East-African/ISO set) stay whole; USD/EUR keep 2 so
// real cent prices ($1.02, $0.50) survive display instead of rounding to "$1"/"$0".
function curDec(cur){ const zero=['UGX','KES','TZS','RWF','BIF','SSP','JPY','KRW','VND','XAF','XOF','GNF','MGA','CLP','PYG','ISK','KMF','XPF'];
  return zero.includes(String(cur||'UGX').toUpperCase())?0:2; }
function fval(f){ let v=f.value; if(f.format==='pct') return v+'%';
  if(f.currency && typeof v==='number'){ const sym=f.currency==='USD'?'$':''; const suf=f.currency==='USD'?'':(' '+f.currency);
    const d=curDec(f.currency);
    return (sym?sym:'')+Number(v).toLocaleString(undefined,{minimumFractionDigits:d,maximumFractionDigits:d})+(sym?'':suf).replace(/^ /,' '); }
  return v; }
function curFmt(v,cur){ cur=cur||'UGX'; const d=curDec(cur);
  const n=Number(v).toLocaleString(undefined,{minimumFractionDigits:d,maximumFractionDigits:d});
  return cur==='USD' ? '$'+n : n+' '+cur; }

async function boot(){
  document.getElementById('view').innerHTML = skeleton();
  const cat = await api('/');
  CFG.user = cat.user || CFG.user;
  CFG.counts = cat.counts || {};
  CFG.flags = cat.flags || {};   // v4.21 — setting-gated menu entries (TenderBot)
  CFG.counts.suggestions = CFG.flags.suggestions || '';   // v4.31 — badge on the Suggestions entry ('' = no badge at zero)
  CFG.currency = cat.currency || { base:'UGX', mode:'base', options:['base','original','USD','UGX','SSP'] };
  initCur();
  const nm = CFG.user.name||'User';
  document.getElementById('unm').textContent = nm;
  document.getElementById('url').textContent = (CFG.user.role||'') + (CFG.user.org ? ' · ' + CFG.user.org : '');
  document.getElementById('uav').textContent = initials(nm);
  document.getElementById('uav').style.background = 'linear-gradient(135deg,#16a34a,#0d9488)';
  // top-bar account menu (always visible, incl. mobile)
  document.getElementById('acctav').textContent = initials(nm);
  document.getElementById('acctnm').textContent = nm;
  document.getElementById('acctwho').textContent = nm;
  document.getElementById('acctrole').textContent = (CFG.user.role||'') + (CFG.user.org ? ' · ' + CFG.user.org : '');
  renderNav(cat.capabilities||[]);
  renderBotnav();   // phone workspaces bar (role-aware); no-op visual on desktop
  const h = parseHash();
  route(h.key || landingKey(), h.id);
}

/* ---- WhatsApp attention engine: so nobody has to keep checking the WhatsApp app ----
   Polls the catalog every 45s: sidebar badges stay live, the tab title shows (n),
   a NEW unread triggers a beep + a desktop notification (tab hidden) or a toast
   (tab visible) that jumps to Chats. While ON Chats/a chat, the view refreshes every
   20s — but never while a modal is open or while you are typing. */
(function(){
  var PREV=null, TITLE=document.title;
  function beep(){ try{ var c=new (window.AudioContext||window.webkitAudioContext)();
    var o=c.createOscillator(), g=c.createGain(); o.connect(g); g.connect(c.destination);
    o.frequency.value=880; g.gain.value=0.05; o.start();
    setTimeout(function(){ try{o.stop(); c.close();}catch(e){} },160); }catch(e){} }
  function b64uArr(s){ const pad='='.repeat((4-s.length%4)%4); const b=atob((s+pad).replace(/-/g,'+').replace(/_/g,'/'));
    const a=new Uint8Array(b.length); for(let i=0;i<b.length;i++) a[i]=b.charCodeAt(i); return a; }
  async function pushEnroll(){
    try{
      if(!('serviceWorker' in navigator) || !('PushManager' in window)) return;
      if(!window.Notification || Notification.permission!=='granted') return;
      const reg=await navigator.serviceWorker.register('/sw.js');
      let sub=await reg.pushManager.getSubscription();
      if(!sub){
        const kr=await fetch('/papi/push/vapid-public',{credentials:'same-origin'}).then(x=>x.json());
        if(!kr||!kr.key) return;
        sub=await reg.pushManager.subscribe({userVisibleOnly:true, applicationServerKey:b64uArr(kr.key)});
      }
      const raw=sub.toJSON();
      await post('/papi/push/subscribe',{endpoint:sub.endpoint, p256dh:(raw.keys&&raw.keys.p256dh)||'', auth:(raw.keys&&raw.keys.auth)||''});
    }catch(e){}
  }
  function askPerm(){ try{ if(window.Notification && Notification.permission==='default'){ Notification.requestPermission().then(function(p){ if(p==='granted') pushEnroll(); }); }
    else if(window.Notification && Notification.permission==='granted'){ pushEnroll(); } }catch(e){}
    document.removeEventListener('click', askPerm); }
  document.addEventListener('click', askPerm);
  function announce(n){
    beep();
    try{
      if(document.hidden && window.Notification && Notification.permission==='granted'){
        var x=new Notification('CloudBSS — WhatsApp', {body:n+' conversation'+(n===1?'':'s')+' need a reply', tag:'cbss-chats'});
        x.onclick=function(){ try{ window.focus(); }catch(e){} route('chats'); try{ x.close(); }catch(e){} };
      } else if(!document.hidden && CUR.key!=='chats' && CUR.key!=='chat'){
        toast('New WhatsApp message — open Chats');
      }
    }catch(e){}
  }
  async function tick(){
    try{
      var cat=await api('/');
      if(!cat || !cat.counts) return;
      CFG.counts=cat.counts;
      if(cat.flags){ CFG.flags=cat.flags; CFG.counts.suggestions=cat.flags.suggestions||''; }   // v4.21 — settings-gated entries appear without a reload; v4.31 keeps the Suggestions count live
      if(cat.capabilities && cat.capabilities.length) renderNav(cat.capabilities);
      var n=Number(cat.counts.chats||0);
      document.title = n>0 ? '('+n+') '+TITLE : TITLE;
      if(PREV!==null && n>PREV) announce(n);
      PREV=n;
    }catch(e){}
  }
  setInterval(tick, 45000);
  setInterval(function(){
    try{
      if(!CUR || (CUR.key!=='chats' && CUR.key!=='chat')) return;
      var ov=document.getElementById('ov'); if(ov && ov.classList.contains('on')) return;
      var ae=document.activeElement; if(ae && (ae.tagName==='INPUT'||ae.tagName==='TEXTAREA')) return;
      refresh();
    }catch(e){}
  }, 20000);
})();

/* ---- WhatsApp-Web mode: split-pane Chats (list + live thread + media + composer) ---- */
window.__WA={active:null,list:null,q:''};
async function waweb(id){
  if(id) window.__WA.active=id;
  document.getElementById('view').innerHTML=
    '<div class="waweb" id="waweb"><div class="wal">'+
    '<div class="wasearch"><input id="waq" placeholder="Search name or number…" value="'+esc(window.__WA.q||'')+'" oninput="window.__WA.q=this.value;waPaint()"></div>'+
    '<div class="walist" id="walist">'+skeleton()+'</div></div>'+
    '<div class="war" id="war"><div class="waempty">'+icon('chats','')+'<div>Pick a conversation</div><div style="font-weight:400;font-size:12px">Everything WhatsApp — names, photos, voice notes, replies — without leaving CloudBSS.</div></div></div></div>';
  try{ window.__WA.list=await api('/chats'); }catch(e){}
  waPaint();
  if(window.__WA.active) openThread(window.__WA.active);
}
function waPaint(){
  const p=window.__WA.list; const el=document.getElementById('walist'); if(!p||!el) return;
  const q=(window.__WA.q||'').toLowerCase();
  let hh='';
  ((p.table&&p.table.rows)||[]).forEach(function(r){
    const id=String((r.link||'').split('/').pop());
    const nm=(r.contact&&r.contact.value)||'', sub=(r.contact&&r.contact.sub)||'';
    if(q && nm.toLowerCase().indexOf(q)<0 && sub.indexOf(q)<0) return;
    const un=r.unread&&r.unread.value; const mode=(r.mode&&r.mode.value)||'';
    hh+='<div class="wac'+(String(window.__WA.active)===id?' on':'')+'" onclick="openThread(\''+id+'\')">'+
      '<div class="waav">'+esc((nm||'?').slice(0,1).toUpperCase())+'</div>'+
      '<div class="wamid"><div class="wanm">'+esc(nm)+'</div><div class="wapv">'+esc((r.last&&r.last.value)||'')+'</div></div>'+
      '<div class="waend"><div class="wat">'+esc((r.when&&r.when.value)||'')+'</div>'+
      (un?'<span class="waun">'+esc(un)+'</span>':'<span class="wam '+(mode==='Agent'?'ag':'bt')+'">'+esc(mode||'Bot')+'</span>')+
      '</div></div>';
  });
  el.innerHTML=hh||'<div class="waempty" style="padding:30px">No conversations match.</div>';
}
async function openThread(id){
  window.__WA.active=String(id);
  try{ history.replaceState(null,'','#/chats/'+id); }catch(e){}
  const box=document.getElementById('waweb'); if(box) box.classList.add('open');
  waPaint();
  const war=document.getElementById('war'); if(!war) return;
  try{
    const p=await api('/chat/'+id);
    const acts=p.actions||[];
    const reply=acts.find(a=>a.key==='reply'); const img=acts.find(a=>a.key==='send_image');
    const doc=acts.find(a=>a.key==='send_file'); const loc=acts.find(a=>a.key==='send_location');
    window.__WA.locAct=loc||null;
    const tk=acts.find(a=>a.key==='takeover'||a.key==='to_bot');
    window.__THREAD_REPLY__=reply||null;
    const msgs=(p.meta&&p.meta.msgs)||[];
    const mh=msgs.map(function(m){
      const out=m.dir==='out'; const bot=out&&m.sender==='bot';
      const body=(m.body||'').trim();
      /* system lines render as centred notes, not bubbles */
      if(m.sender==='system'){
        return body?'<div class="sys">'+esc(body)+' · '+esc(m.at||'')+'</div>':'';
      }
      let inner='';
      if(m.media){
        if(m.media.kind==='image') inner+='<img class="wimg" loading="lazy" src="'+esc(m.media.src)+'" onclick="try{window.open(this.src)}catch(e){}">';
        else if(m.media.kind==='audio') inner+='<audio controls preload="none" style="max-width:240px" src="'+esc(m.media.src)+'"></audio>';
        else if(m.media.kind==='video') inner+='<video controls preload="none" class="wimg" style="max-width:280px" src="'+esc(m.media.src)+'"></video>';
        else if(m.media.kind==='location') inner+='<div class="wloc">📍 <b>'+esc(m.media.name||'Location shared')+'</b>'+(m.media.address?'<div class="oldmedia">'+esc(m.media.address)+'</div>':'')+'<a href="'+esc(m.media.maps)+'" target="_blank">Open in Google Maps ↗</a></div>';
        else inner+='<a href="'+esc(m.media.src)+'" target="_blank" download>📎 '+esc(m.media.name||'Attachment')+'</a>';
      }
      const ph=body.match(/^\[(image|photo|voice|audio|video|sticker|document|file)\]$/i);
      if(!m.media && ph){
        inner+='<div class="oldmedia">📎 '+esc(ph[1].toLowerCase())+' (sent before media logging)</div>';
      } else if(body && body!=='[photo]'){
        inner+='<div>'+esc(body)+'</div>';
      }
      /* nothing to show at all → skip the row entirely (this was the ghost-bubble gap bug) */
      if(!inner) return '';
      /* Juba reality: Arabic in → English team. Cached translation shows forever; else offer 🌐. */
      if(!out){
        if(m.tr){ inner+='<div class="trline">🌐 '+esc(m.tr)+'</div>'; }
        else if(m.id && /[\u0600-\u06FF]/.test(body)){
          inner+='<button class="trbtn" onclick="trMsg('+m.id+')">🌐 Translate</button>';
        }
      }
      return '<div class="msg '+(out?'out':'in')+(bot?' bot':'')+'">'+
        (out?'<div class="who">'+(bot?'🤖 Bot':'🧑‍💼 Agent')+'</div>':'')+inner+
        '<div class="when">'+esc(m.at||'')+'</div></div>';
    }).join('');
    war.innerHTML=
      '<div class="wahd"><button class="waback" onclick="document.getElementById(\'waweb\').classList.remove(\'open\')">←</button>'+
      '<div class="waav">'+esc(((p.object&&p.object.title)||'?').slice(0,1).toUpperCase())+'</div>'+
      '<div class="wahm"><div class="wanm">'+esc((p.object&&p.object.title)||'')+'</div><div class="wapv">'+esc((p.object&&p.object.subtitle)||'')+'</div></div>'+
      (tk?'<button class="btn sm" onclick=\'doAction('+JSON.stringify(tk).replace(/'/g,"&#39;")+')\'>'+esc(tk.label)+'</button>':'')+
      '</div>'+
      '<div class="thread" id="thread">'+(mh||'<div class="waempty" style="padding:30px">No messages logged yet — your first reply starts the transcript.</div>')+'</div>'+
      (reply?('<div class="composer">'+
        (img?'<button class="attach" title="Send photo" onclick=\'doAction('+JSON.stringify(img).replace(/'/g,"&#39;")+')\'>📎</button>':'')+
        (doc?'<button class="attach" title="Send document (PDF…)" onclick=\'doAction('+JSON.stringify(doc).replace(/'/g,"&#39;")+')\'>📄</button>':'')+
        (loc?'<button class="attach" title="Send location" onclick="sendLoc()">📍</button>':'')+
        '<button class="attach'+(window.__WA.arOut?' on':'')+'" id="arbtn" title="Reply in Arabic (translates before sending)" onclick="window.__WA.arOut=!window.__WA.arOut;this.classList.toggle(\'on\')">🌐</button>'+
        '<input id="cmpin" placeholder="Type a message…" autocomplete="off" onkeydown="if(event.key===\'Enter\')sendThread()">'+
        '<button id="cmpbtn" onclick="sendThread()">Send</button></div>'):'');
    const th=document.getElementById('thread'); if(th) th.scrollTop=th.scrollHeight;
  }catch(e){}
}
// A brand-new shop (nothing sold or stocked yet) lands on the Get-started checklist;
// an established shop lands on the Morning Briefing.
/* True on any phone/tablet: narrow width OR a touch-primary pointer OR a mobile user-agent. Drives the
   app-style landing; the CSS uses the same (max-width:880px),(pointer:coarse) so chrome + landing agree. */
function isAppUI(){
  try{
    if(window.matchMedia && window.matchMedia('(max-width:880px)').matches) return true;
    if(window.matchMedia && window.matchMedia('(pointer:coarse)').matches) return true;
    if(/iphone|ipad|ipod|android|mobile|silk|kindle|blackberry|iemobile/i.test(navigator.userAgent||'')) return true;
  }catch(e){}
  return false;
}
function landingKey(){
  const c = CFG.counts||{};
  const started = ['product','customers','supplier','salesOrder','quotations','invoice','payment']
    .some(k => Number(c[k]||0) > 0);
  if(!started) return 'setup';
  // Mobile-first: any phone/tablet (narrow OR touch-primary) lands on the app Today screen; a desktop
  // with a mouse keeps the classic briefing. Touch detection so wide phones/tablets aren't misread as desktop.
  if(isAppUI()) return 'bos.today';
  return 'briefing';
}
function parseHash(){ const p=location.hash.replace('#/','').split('/'); return { key:p[0]||'', id:p[1]||null }; }
function renderNav(caps){
  const keys = new Set(caps.map(c=>c.key));
  CFG.capKeys = keys;   // shared with the mobile workspace bar so it hides workspaces the role can't see
  // A SOON item shows if its underlying entity exists (has a count) even without a list capability yet.
  /* pnl is a legacy-iframe screen with no capability of its own — gate it on the
     permission-filtered finance capability so restricted roles don't see P&L. */
  /* v4.21 — TenderBot is an owner tool behind a tenant setting, not a capability: the server sends
     flags.tenders (owner AND Settings → Tenders → Watch for tenders is ON). Off = no menu entry. */
  /* v4.30 — the Solar Advisor was shown to every shop, so a grocery had "Solar Advisor" sitting
     in its Sales menu. It now follows the business type. An EMPTY vertical means the server did
     not tell us (older build, or the flags block threw), and that deliberately shows the entry —
     this gate can only ever reveal too much, never hide a tool someone is using. */
  const vert = (CFG.flags && typeof CFG.flags.vertical === 'string') ? CFG.flags.vertical : '';
  const solarish = k => k!=='advisor' || vert==='' || vert==='solar';
  /* Travel Vertical (M1) — Trips is a travel-agency screen. Unlike the Solar gate above it is
     STRICT: it shows only when the server says the business type is travel, so no other shop
     ever gains a menu entry from this change. */
  const travelish = k => k!=='trips' || vert==='travel';
  /* Sales Brain (DishNet plan, Phase 3) — Internet-provider businesses only; STRICT like Trips. */
  const ispish = k => k!=='salesBrain' || vert==='isp';
  const has = k => ((k==='pnl' && keys.has('finance')) || (k==='books' && keys.has('finance')) || (k==='partners' && keys.has('finance')) || k==='settings' || k==='advisor' || k==='pos' || k==='ai.campaign' || (k==='tenders' && !!(CFG.flags && CFG.flags.tenders)) || (k==='suggestions' && !!(CFG.flags && (CFG.flags.suggestions || CFG.flags.research))) || k==='evidence' || (k==='engagement' && keys.has('campaigns')) || k==='vision.review' || SF_KEYS.has(k) || keys.has(k) || (SOON[k] && CFG.counts[NAVCOUNT[k]||k] != null)) && solarish(k) && travelish(k) && ispish(k);
  // The top-bar POS launcher follows the same gate as the menu entry.
  try{ var pb=document.getElementById('posTop'); if(pb) pb.style.display = has('pos') ? 'flex' : 'none'; }catch(e){}
  let html='';
  GROUPS.forEach(([g,items])=>{
    const avail=items.filter(has);
    if(!avail.length) return;
    html+='<div class="ngrp">'+g+'</div>';
    avail.forEach(k=>{
      const c = CFG.counts[NAVCOUNT[k]||k];
      const cnt = (c!=null && c!=='') ? '<span class="cnt">'+fmtCount(c)+'</span>' : '';
      html+='<div class="nitem" data-key="'+k+'" onclick="route(\''+k+'\')">'+icon(k)+'<span class="nl">'+(LABELS[k]||k)+'</span>'+cnt+'</div>';
    });
  });
  document.getElementById('nav').innerHTML=html;
}
function setActive(key){ document.querySelectorAll('.nitem').forEach(n=>n.classList.toggle('on', n.dataset.key===key)); setActiveWorkspace(key); }

/* ================= MOBILE WORKSPACES — Today · Customers · Operations · Finance · Me =================
   Phone-first "operating system" chrome: role-aware workspaces in the bottom bar, a context-aware +
   that changes actions per workspace, and action-oriented hubs. Desktop is untouched (bar/FAB hidden). */
const WSICON = {
  today:'M3 10.5 12 3l9 7.5M5 9.5V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9.5',
  customers:'M9 8a3.1 3.1 0 1 0 .01 0M3.6 20a5.4 5.4 0 0 1 10.8 0M16 6.3a3 3 0 0 1 0 5.4',
  operations:'M21 8l-9-5-9 5 9 5 9-5zM3 8v8l9 5 9-5V8',
  finance:'M3 7h18v12a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM3 7l2.5-4h13L21 7M12 12v4M9.5 14h5',
  me:'M12 8a3.4 3.4 0 1 0 .01 0M5.5 20a6.5 6.5 0 0 1 13 0'
};
function svgIc(d,extra){ return '<svg class="ic'+(extra?' '+extra:'')+'" viewBox="0 0 24 24"><path d="'+d+'"/></svg>'; }
const WS = {
  today:      { label:'Today',      route:'bos.today', members:['bos.today','briefing'] },
  customers:  { label:'Customers',  route:'customers', members:['customers','leads','followups'] },
  operations: { label:'Operations', hub:true, sub:'Stock, orders & fulfilment',
                members:['salesOrders','products','categories','purchase.process','suppliers','lpos','warehouse','dispatch','consignments','procurement.hub','demand.plans','juba.stock','scheduled'] },
  finance:    { label:'Finance',    hub:true, sub:'Money in & money out',
                members:['collections','invoices','payments','quotations','cashbook','finance','pnl','books','partners'] },
  me:         { label:'Me',         hub:true, sub:'', members:[] }
};
const WS_ORDER = ['today','customers','operations','finance','me'];
/* Action-oriented card labels (work, not database tables). */
const HUBCARD = {
  collections:{t:'Money to Collect',c:'overdue & due'}, invoices:{t:'Waiting for Payment',c:'issued'},
  payments:{t:'Receipts',c:'money received'}, quotations:{t:'Quotes Waiting',c:'to confirm'},
  cashbook:{t:'Cash Book',c:'cash in & out'}, finance:{t:'Finance',c:'overview'}, pnl:{t:'Profit & Loss',c:''}, books:{t:'Books',c:'full report'}, partners:{t:'Partners',c:'capital & drawings'},
  salesOrders:{t:'Open Orders',c:'to fulfil'}, products:{t:'Products',c:'catalogue'}, categories:{t:'Categories',c:''},
  'purchase.process':{t:'Items to Receive',c:'purchasing'}, suppliers:{t:'Suppliers',c:''}, lpos:{t:'Purchase Orders',c:'LPOs'},
  warehouse:{t:'Low Stock',c:'reorder'}, dispatch:{t:'To Deliver',c:'dispatch'}, consignments:{t:'Consignments',c:''},
  'procurement.hub':{t:'Procurement',c:''}, 'demand.plans':{t:'Demand Plans',c:''}, 'juba.stock':{t:'Juba Stock',c:''},
  scheduled:{t:'Scheduled',c:'deliveries'}
};
/* Context-aware create actions per workspace (only real, permitted screens are offered). */
const WS_ADD = {
  today:      [['task.new','New Task'],['quotations','New Quote'],['invoices','New Invoice'],['payments','Receive Payment'],['cashbook','Expense'],['customers','New Customer']],
  customers:  [['customers','New Customer'],['quotations','New Quote'],['payments','Receive Payment'],['task.new','New Task']],
  operations: [['task.new','New Task'],['lpos','New LPO'],['purchase.process','New Purchase'],['products','New Product'],['salesOrders','New Order']],
  finance:    [['cashbook','Expense'],['payments','Receive Payment'],['invoices','New Invoice']],
  me:         []
};
var WS_CUR = 'today';
function hasScreen(k){
  var s = CFG.capKeys;
  if(k==='task.new') return true;   // the "+ New task" action (owner/manager gate is enforced server-side)
  if(k==='pnl') return !!(s && s.has('finance'));
  if(k==='books') return !!(s && s.has('finance'));
  if(k==='partners') return !!(s && s.has('finance'));
  if(k==='settings' || k==='guide' || k==='pos' || k==='ai.campaign') return true;
  if(SF_KEYS.has(k)) return true;
  if(s && s.has(k)) return true;
  return !!(SOON[k] && CFG.counts && CFG.counts[NAVCOUNT[k]||k]!=null);
}
function wsVisible(id){ if(id==='today'||id==='me') return true; return WS[id].members.some(hasScreen); }
function renderBotnav(){
  var bar=document.getElementById('botnav'); if(!bar) return;
  bar.innerHTML = WS_ORDER.filter(wsVisible).map(function(id){
    return '<button class="bn" data-ws="'+id+'" onclick="openWorkspace(\''+id+'\')">'+svgIc(WSICON[id])+'<span>'+WS[id].label+'</span></button>';
  }).join('');
}
function openWorkspace(id){ var w=WS[id]; if(!w) return; w.hub ? route('ws.'+id) : route(w.route); }
function wsOf(key){
  if(key && key.indexOf('ws.')===0) return key.slice(3);
  for(var i=0;i<WS_ORDER.length;i++){ var id=WS_ORDER[i]; if(WS[id].route===key || WS[id].members.indexOf(key)>=0) return id; }
  return null;
}
function setActiveWorkspace(key){
  var id=wsOf(key), bar=document.getElementById('botnav');
  if(id) WS_CUR=id;
  if(bar) bar.querySelectorAll('.bn[data-ws]').forEach(function(n){ n.classList.toggle('on', n.getAttribute('data-ws')===id); });
  var fab=document.getElementById('fab'); if(fab) fab.style.display = (id==='me') ? 'none' : '';
}
/* Workspace hubs: an action-oriented card menu that opens the existing screens. */
function renderHub(id){
  var w=WS[id]; if(!w) return;
  if(id==='me') return renderMeHub();
  var cards=w.members.filter(hasScreen).map(function(k){
    var m=HUBCARD[k]||{t:(LABELS[k]||k),c:''};
    var cnt=CFG.counts?CFG.counts[NAVCOUNT[k]||k]:null;
    var sub=(cnt!=null&&cnt!=='')?(fmtCount(cnt)+(m.c?' · '+m.c:'')):(m.c||'');
    return '<button class="hubcard" onclick="route(\''+k+'\')"><span class="hi">'+icon(k)+'</span>'
      +'<span><span class="ht">'+m.t+'</span>'+(sub?'<br><span class="hc">'+sub+'</span>':'')+'</span></button>';
  }).join('');
  document.getElementById('view').innerHTML='<div class="hubhead">'+w.label+'</div>'
    +'<div class="hubsub">'+(w.sub||'')+'</div>'
    +'<div class="hubgrid">'+(cards||'<div class="hubsub">Nothing here for your role.</div>')+'</div>';
}
/* Modern Me (design v1.0): profile header + grouped account rows. */
function renderMeHub(){
  var u=CFG.user||{}, nm=u.name||'You', role=u.role||'', org=u.org||'';
  var init = (nm && nm!=='You') ? nm.trim().split(/\s+/).map(function(w){return (w[0]||'')}).slice(0,2).join('').toUpperCase() : '·';
  var sub = role ? (role + (org?' · '+org:'')) : (org||'—');
  var chev='<span class="me-chev"><svg class="i" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></span>';
  var IC={
    home:'<path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M9 13h.01M15 9h.01M15 13h.01"/>',
    wa:'<path d="M21 11.5a8.4 8.4 0 0 1-11.9 7.6L3 21l1.9-6A8.4 8.4 0 1 1 21 11.5Z"/>',
    help:'<circle cx="12" cy="12" r="9"/><path d="M12 8v4l3 2"/>',
    panel:'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 9h18"/>',
    out:'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>'
  };
  function row(ic,label,right,onclick,danger){
    return '<div class="me-row'+(danger?' dgr':'')+'" onclick="'+onclick+'"><div class="me-ci'+(danger?' dgr':'')+'"><svg class="i" viewBox="0 0 24 24">'+ic+'</svg></div><div class="me-l">'+label+'</div>'+(right||'')+'</div>';
  }
  var g1='';
  if(hasScreen('settings')) g1+=row(IC.home,'Business profile &amp; settings',chev,"route('settings')");
  g1+=row(IC.wa,'WhatsApp connection',chev,"route('wa-connect')");
  if(hasScreen('guide')) g1+=row(IC.help,'Help &amp; training',chev,"route('guide')");
  var g2='';
  g2+=row(IC.panel,'Open classic panel',chev,"location.href='/panel?classic=1'");
  g2+=row(IC.out,'Sign out','',"signOut()",true);
  document.getElementById('view').innerHTML='<div class="mtoday"><div class="bcl-h"><div class="bcl-title">Me</div></div>'
    +'<div class="me-prof"><div class="me-av">'+esc(init)+'</div><div><div class="me-nm">'+esc(nm)+'</div><div class="me-rl">'+esc(sub)+'</div></div></div>'
    +'<div class="me-group">'+g1+'</div><div class="me-group">'+g2+'</div></div>';
}
/* Context-aware create sheet — items depend on the workspace you're in. */
function openQuickAdd(){
  var items=(WS_ADD[WS_CUR]||WS_ADD.today).filter(function(x){ return hasScreen(x[0]); });
  if(!items.length) items=WS_ADD.today.filter(function(x){ return hasScreen(x[0]); });
  document.getElementById('qgrid').innerHTML = items.map(function(x){
    return '<button class="qadd" onclick="quickAdd(\''+x[0]+'\')"><span class="qaic">'+icon(x[0])+'</span>'+x[1]+'</button>';
  }).join('');
  document.getElementById('qsheet').classList.add('on');
}

/* ---- global report-currency selector ---- */
const CUR_LABELS = { base:'Base currency', original:'Original currency', USD:'USD', UGX:'UGX', SSP:'SSP' };
function initCur(){
  // Session-persistent override; falls back to the tenant's configured mode.
  let saved=null; try{ saved=localStorage.getItem('v3cur'); }catch(e){}
  const c=CFG.currency||{};
  CFG.cur = saved || (c.override || (c.mode==='original'?'original':'base'));
  renderCurpick();
}
function renderCurpick(){
  const el=document.getElementById('curpick'); if(!el) return;
  const c=CFG.currency||{}; const opts=c.options||['base','original','USD','UGX','SSP'];
  const cur=CFG.cur||'base';
  const label=(k)=> k==='base' ? ('Base ('+(c.base||'UGX')+')') : (CUR_LABELS[k]||k);
  el.innerHTML='<span class="cl">'+icon('dollar','ic-sm')+'Reports</span>'+
    '<select id="curSel" onchange="setCur(this.value)">'+
    opts.map(o=>'<option value="'+o+'"'+(o===cur?' selected':'')+'>'+esc(label(o))+'</option>').join('')+
    '</select>';
}
function setCur(v){
  CFG.cur=v; try{ localStorage.setItem('v3cur', v); }catch(e){}
  renderCurpick();   // keep the top-bar selector reflecting the active choice across navigation
  // Sidebar catalog counts are currency-independent; just reload the current view.
  if(CUR && CUR.key){ route(CUR.key, CUR.id); }
}

async function route(key, id){
  // Normalise id to null (not undefined) so the hashchange guard (which compares against parseHash's
  // null id) doesn't fire a spurious second route that re-renders and wipes a just-applied filter.
  id = id != null ? id : null;
  closeNav();
  CUR={key,id}; REC_TAB='overview'; location.hash='#/'+key+(id?'/'+id:''); setActive(key);
  if(key==='transport'){ location.href='/transport'; return; }
  if(key==='chats'){ CUR.key='chats'; CUR.id=id||null; setActive('chats'); waweb(id||null); return; }
  if(key==='payments'){ if(isAppUI()){ bosPayments(); return; } document.getElementById('view').innerHTML='<div class="crumb">Payments</div><iframe title="Payments" src="/panel/payments-manager" style="width:100%;height:calc(100vh - 150px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  if(key==='pnl'){ if(isAppUI()){ bosPnl(); return; } document.getElementById('view').innerHTML='<div class="crumb">Profit & Loss</div><iframe title="Profit and Loss" src="/panel/pnl-report" style="width:100%;height:calc(100vh - 150px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  // v4.47 — Partners (capital accounts, introductions and DRAWINGS) existed only in the classic
  // panel, so an owner living in V3 had no way to record taking money out of the business.
  // Embedded per V3-FIRST rule 3; a native workspace when it earns one.
  if(key==='partners'){ document.getElementById('view').innerHTML='<div class="crumb">Partners</div><iframe title="Partners" src="/panel/partners?embed=1" style="width:100%;height:calc(100vh - 130px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  if(key==='books'){ document.getElementById('view').innerHTML='<div class="crumb">Books</div><iframe title="Books" src="/panel/situation-report" style="width:100%;height:calc(100vh - 130px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  if(key==='wa-connect'){ document.getElementById('view').innerHTML='<div class="crumb">Connect WhatsApp</div><iframe title="Connect WhatsApp" src="/panel/setup" style="width:100%;height:calc(100vh - 150px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  if(key==='settings'){ if(isAppUI()){ bosSettings(); return; } document.getElementById('view').innerHTML='<iframe title="Settings" src="/panel/settings-manager" style="width:100%;height:calc(100vh - 96px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  // POS till — V3 is the front door (V3-FIRST.md); the till screen embeds edge-to-edge like Settings.
  if(key==='pos'){ document.getElementById('view').innerHTML='<iframe title="POS Till" src="/panel/pos?embed=1" style="width:100%;height:calc(100vh - 96px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  // Consignments (v4.7) — the full Kampala→Juba management page (create, load orders, pack boxes,
  // custody chain, market stock, Juba requests) embeds like POS; richer than the old list view.
  if(key==='consignments'){ document.getElementById('view').innerHTML='<iframe title="Consignments" src="/panel/consignment?embed=1" style="width:100%;height:calc(100vh - 96px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  // AI Campaign generator (v4.5) — owner tool; embedded like settings/pos.
  if(key==='ai.campaign'){ document.getElementById('view').innerHTML='<iframe title="AI Campaign" src="/panel/ai-campaign?embed=1" style="width:100%;height:calc(100vh - 96px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  // Customer Engagement (re-engagement plan) — read-only dashboard, embedded like the other owner tools.
  if(key==='engagement'){ document.getElementById('view').innerHTML='<iframe title="Customer Engagement" src="/panel/engagement?embed=1" style="width:100%;height:calc(100vh - 96px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  // Vision Stock & Loss — read-only movement-exception review feed, embedded like the other owner tools.
  if(key==='vision.review'){ document.getElementById('view').innerHTML='<iframe title="Stock & Loss" src="/panel/vision-review?embed=1" style="width:100%;height:calc(100vh - 96px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  // TenderBot (v4.21) — the AI bid desk; owner tool, embedded like POS/AI Campaign. Menu entry only
  // appears when Settings → Tenders → Watch for tenders is ON (server flag), but the route always works.
  if(key==='evidence'){ document.getElementById('view').innerHTML='<iframe title="Evidence" src="/papi/evidence?embed=1" style="width:100%;height:calc(100vh - 96px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  if(key==='suggestions'){ document.getElementById('view').innerHTML='<iframe title="Suggestions" src="/papi/research?embed=1" style="width:100%;height:calc(100vh - 96px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  if(key==='tenders'){ document.getElementById('view').innerHTML='<iframe title="TenderBot" src="/papi/tenders?embed=1" style="width:100%;height:calc(100vh - 96px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  // Help & Training — the in-product Training Center (replaces the old server guide workspace).
  if(key==='guide'){ document.getElementById('view').innerHTML='<iframe title="Training" src="/panel/training" style="width:100%;height:calc(100vh - 96px);border:0;border-radius:12px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.06)"></iframe>'; return; }
  if(key==='advisor'){ advisorHome(id); return; }
  if(key==='advisor.kb'){ advKnowledge(); return; }
  if(key==='storefront.collections'){ sfCollectionsPage(); return; }
  if(key==='storefront.kits'){ sfKitsPage(); return; }
  if(key==='storefront.delivery'){ sfDeliveryPage(); return; }
  // The modern card screens are the PHONE app; on a desktop with a mouse we keep the classic denser
  // views (table + hubs). isAppUI() is the same phone/tablet test the landing uses, so chrome agrees.
  if(key==='ws.finance' && isAppUI()){ bosFinance(); return; }     // Modern Finance hub — phones only
  if(key==='ws.operations' && isAppUI()){ bosOperations(); return; } // Modern Operations hub — phones only
  if(key.indexOf('ws.')===0){ renderHub(key.slice(3)); return; }   // mobile workspace hub (Me)
  if(key==='bos.today'){ bosToday(); return; }                     // BOS Slice 3 — the day's work
  if(key==='bos.timeline'){ bosTimeline(id); return; }             // Customer 360 (no classic desktop route; reached only from the app)
  if(key==='bos.task'){ bosTaskDetail(id); return; }               // Native in-app task detail
  if(key==='customers' && isAppUI()){ bosCustomers(); return; }    // Modern Customers list — phones only
  if(SOON[key] && !id){ renderSoon(key); return; }
  document.getElementById('view').innerHTML=skeleton();
  let path='/'+key+(id?'/'+id:'');
  // A pending server scope (e.g. category → products) is appended to this one fetch, then cleared.
  if(PENDING_SCOPE && PENDING_SCOPE.key===key){ path += (path.indexOf('?')<0?'?':'&')+PENDING_SCOPE.query; PENDING_SCOPE=null; }
  const p = await api(path);
  if(p._error){ document.getElementById('view').innerHTML=emptyState('bolt','Could not load','You may not have access to this workspace ('+p._error+').'); return; }
  renderView(p);
}
/* ========================= SOLAR PROJECT ADVISOR (Slices 2–3 UI) =========================
   A bespoke screen (not a capability payload): discovery wizard -> deterministic sizing preview ->
   save project -> equipment + generate proposal -> proposal view. All figures come from the server
   engine (/papi/advisor/*); this screen only collects answers and renders results. */
var ADV = { schema:null, projectId:null };

async function advisorHome(id){
  if(id){ return advisorOpenProject(id); }
  var v=document.getElementById('view');
  v.innerHTML='<div class="crumb">Solar Advisor</div>'+
    '<div class="rec-head"><div class="rec-av" style="background:#d97706">'+icon('advisor','ic-lg')+'</div>'+
    '<div class="idn"><h1>Solar Project Advisor</h1><div class="sub">Guide the customer from discovery to a sized, professional proposal.</div></div>'+
    '<div class="acts inl" style="margin-left:auto"><button class="btn" onclick="route(\'advisor.kb\')">Knowledge</button><button class="btn" onclick="advPackages()">Start from a package</button><button class="btn pri" onclick="advisorNew()">New project</button></div></div>'+
    '<div id="advList" class="card"><div class="b">Loading…</div></div>';
  var r=await getQuery('/papi/advisor/projects',{});
  var rows=(r&&r.projects)||[];
  var body= rows.length ? rows.map(advProjectRow).join('')
    : '<div class="b" style="padding:40px;text-align:center;color:var(--faint)">No projects yet. Click “New project” to run a discovery.</div>';
  var el=document.getElementById('advList'); if(el) el.innerHTML='<div class="h">'+icon('advisor','ic-sm')+'Recent projects</div>'+body;
}

function advProjectRow(p){
  var r=(p.sizing&&p.sizing.result)||{};
  var bits=[];
  if(r.pv_array_w) bits.push('PV '+advN(r.pv_array_w)+' W');
  if(r.battery_bank_wh) bits.push('Battery '+advN(r.battery_bank_wh)+' Wh');
  if(r.inverter_w) bits.push('Inverter '+advN(r.inverter_w)+' W');
  return '<div class="qrow" style="cursor:pointer" onclick="route(\'advisor\',\''+p.id+'\')">'+
    '<div class="idn"><b>'+esc(p.name||('Project #'+p.id))+'</b> '+statusBadge(p.status)+
    '<div class="sub mut">'+esc(bits.join(' · ')||'Not sized yet')+'</div></div></div>';
}

/* ---- Solution packages: pick a ready-made solution -> one-click sized project + proposal ---- */
async function advPackages(){
  document.getElementById('view').innerHTML=
    '<div class="crumb" style="cursor:pointer" onclick="route(\'advisor\')">Solar Advisor</div>'+
    '<div class="rec-head"><div class="idn"><h1>Start from a package</h1><div class="sub">Pick a ready-made solution — it pre-fills a sized project and a full proposal you can tailor.</div></div></div>'+
    '<div id="advPkgs" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px">Loading…</div>';
  var r=await getQuery('/papi/advisor/packages',{profile:'solar'});
  var pkgs=(r&&r.packages)||[];
  var el=document.getElementById('advPkgs'); if(!el) return;
  el.innerHTML = pkgs.length ? pkgs.map(advPkgCard).join('') : '<div class="mut">No packages available.</div>';
}
function advPkgCard(p){
  var m=p.marketing||{};
  var points=(m.points||[]).map(function(x){ return '<li>'+esc(x)+'</li>'; }).join('');
  var n=(p.equipment||[]).length;
  return '<div class="card"><div class="b">'+
    '<div style="font-weight:700;font-size:15px">'+esc(p.name)+'</div>'+
    '<div class="mut" style="font-size:12px;margin:2px 0 8px">'+esc(p.segment||'')+' · '+n+' items</div>'+
    (m.tagline?'<div style="font-style:italic;margin-bottom:6px;color:var(--faint)">“'+esc(m.tagline)+'”</div>':'')+
    (p.summary?'<div style="font-size:13px;line-height:1.5;margin-bottom:6px">'+esc(p.summary)+'</div>':'')+
    (points?'<ul style="margin:0 0 10px;padding-left:18px;line-height:1.5;font-size:13px">'+points+'</ul>':'')+
    '<button class="btn pri" onclick="advStartPackage(\''+esc(p.key)+'\',this)">Use this package</button>'+
    '</div></div>';
}
async function advStartPackage(key,btn){
  if(btn){ btn.disabled=true; btn.textContent='Building…'; }
  var r=await post('/papi/advisor/packages/'+encodeURIComponent(key)+'/start',{profile:'solar',overrides:{}});
  if(!r||!r.ok){ toast('Could not start from package', true); if(btn){ btn.disabled=false; btn.textContent='Use this package'; } return; }
  toast('Project & proposal created');
  route('advisor', r.project_id);
}

/* ---- Knowledge Library: FAQs, learning, objection handling, WhatsApp-ready replies ---- */
var KB = { type:'', q:'' };
var KB_TYPE_LABEL = { faq:'FAQ', education:'Learn', objection:'Objection', message:'WhatsApp' };
function advKnowledge(){
  setActive('advisor');
  document.getElementById('view').innerHTML=
    '<div class="crumb" style="cursor:pointer" onclick="route(\'advisor\')">Solar Advisor</div>'+
    '<div class="rec-head"><div class="idn"><h1>Knowledge Library</h1><div class="sub">FAQs, talking points, objection handling and ready-to-send WhatsApp replies.</div></div></div>'+
    '<div class="card"><div class="b">'+
      '<input id="kbq" class="set-in" placeholder="Search the library…" oninput="kbSearch()" style="margin-bottom:8px">'+
      '<div id="kbTabs" class="set-segs">'+kbTabs()+'</div>'+
    '</div></div><div id="kbList">Loading…</div>';
  kbLoad();
}
function kbTabs(){
  return [['','All'],['faq','FAQs'],['education','Learn'],['objection','Objections'],['message','WhatsApp']]
    .map(function(t){ return '<button class="set-seg'+(KB.type===t[0]?' on':'')+'" onclick="kbType(\''+t[0]+'\')">'+t[1]+'</button>'; }).join('');
}
function kbType(t){ KB.type=t; var el=document.getElementById('kbTabs'); if(el) el.innerHTML=kbTabs(); kbLoad(); }
var _kbTimer=null;
function kbSearch(){ KB.q=((document.getElementById('kbq')||{}).value)||''; clearTimeout(_kbTimer); _kbTimer=setTimeout(kbLoad,200); }
async function kbLoad(){
  var r=await getQuery('/papi/advisor/knowledge',{ profile:'solar', type:KB.type, q:KB.q });
  var entries=(r&&r.entries)||[];
  var el=document.getElementById('kbList'); if(!el) return;
  el.innerHTML = entries.length ? entries.map(kbCard).join('')
    : '<div class="card"><div class="b mut" style="text-align:center;padding:30px">No matches. Try another search.</div></div>';
}
function kbCard(e){
  var badge='<span class="cnt" style="background:#eef0f3">'+esc(KB_TYPE_LABEL[e.type]||e.type)+'</span>';
  var wa = e.whatsapp
    ? '<div style="background:#f0f7f0;border:1px solid #cfe6cf;border-radius:10px;padding:10px;margin-top:8px;font-size:13px;line-height:1.5">'+esc(e.whatsapp)+
      '<div style="margin-top:8px"><button class="btn" data-wa="'+esc(e.whatsapp)+'" onclick="kbCopy(this)">Copy message</button></div></div>'
    : '';
  return '<div class="card"><div class="b">'+
    '<div style="display:flex;justify-content:space-between;gap:8px;align-items:flex-start"><div style="font-weight:700">'+esc(e.title)+'</div>'+badge+'</div>'+
    '<div class="mut" style="font-size:12px;margin:2px 0 6px">'+esc(e.category||'')+'</div>'+
    '<div style="font-size:13px;line-height:1.55">'+esc(e.body||'')+'</div>'+wa+'</div></div>';
}
function kbCopy(btn){
  var txt=btn.getAttribute('data-wa')||'';
  try{ navigator.clipboard.writeText(txt).then(function(){ toast('Copied to clipboard'); }, function(){ kbFallbackCopy(txt); }); }
  catch(e){ kbFallbackCopy(txt); }
}
function kbFallbackCopy(txt){
  var t=document.createElement('textarea'); t.value=txt; document.body.appendChild(t); t.select();
  try{ document.execCommand('copy'); toast('Copied to clipboard'); }catch(e){ toast('Copy failed', true); }
  t.remove();
}
async function advLoadSchema(){ if(ADV.schema) return; var r=await getQuery('/papi/advisor/schema',{profile:'solar'}); ADV.schema=(r&&r.schema)||null; }

async function advisorNew(){
  await advLoadSchema();
  ADV.projectId=null;
  if(!ADV.schema){ toast('Could not load the questionnaire', true); return; }
  var secs=(ADV.schema.sections||[]).map(function(sec){
    return '<div class="card"><div class="h">'+esc(sec.title)+'</div><div class="b">'+(sec.fields||[]).map(advField).join('')+'</div></div>';
  }).join('');
  document.getElementById('view').innerHTML=
    '<div class="crumb" style="cursor:pointer" onclick="route(\'advisor\')">Solar Advisor</div>'+
    '<div class="rec-head"><div class="idn"><h1>New solar project</h1><div class="sub">Answer the discovery questions, then calculate the system.</div></div>'+
    '<div class="acts inl" style="margin-left:auto"><button class="btn" onclick="advCalculate()">Calculate</button><button class="btn pri" onclick="advSave()">Save project</button></div></div>'+
    secs+'<div id="advResult"></div>';
  advAddLoad();
}

function advField(f){
  var id='adv_'+f.key;
  if(f.type==='loads') return advLoadsField(f);
  var lbl='<label>'+esc(f.label)+(f.required?' *':'')+'</label>';
  var help=f.help?'<div class="mut" style="font-size:12px;margin-top:3px">'+esc(f.help)+'</div>':'';
  var input;
  if(f.type==='enum'){
    input='<select id="'+id+'" class="set-in"><option value=""></option>'+(f.options||[]).map(function(o){ return '<option value="'+esc(o)+'"'+(f.default===o?' selected':'')+'>'+esc(o)+'</option>'; }).join('')+'</select>';
  } else if(f.type==='number'){
    input='<input id="'+id+'" class="set-in" inputmode="decimal"'+(f.unit?(' placeholder="'+esc(f.unit)+'"'):'')+(f.default!=null?(' value="'+esc(f.default)+'"'):'')+'>';
  } else {
    input='<input id="'+id+'" class="set-in">';
  }
  return '<div class="fs-field">'+lbl+input+help+'</div>';
}

function advLoadsField(f){
  return '<div class="fs-field"><label>'+esc(f.label)+'</label>'+
    (f.help?'<div class="mut" style="font-size:12px;margin-bottom:6px">'+esc(f.help)+'</div>':'')+
    '<div id="advLoads"></div>'+
    '<button class="btn" style="margin-top:8px" onclick="advAddLoad()">+ Add appliance</button></div>';
}
function advAddLoad(row){
  row=row||{}; var c=document.getElementById('advLoads'); if(!c) return;
  var d=document.createElement('div'); d.className='advLoadRow';
  d.style.cssText='display:grid;grid-template-columns:2fr 1fr .8fr .9fr auto auto;gap:6px;margin-bottom:6px;align-items:center';
  d.innerHTML='<input class="set-in lr-name" placeholder="Appliance" value="'+esc(row.name||'')+'">'+
    '<input class="set-in lr-watts" inputmode="decimal" placeholder="Watts" value="'+esc(row.watts||'')+'">'+
    '<input class="set-in lr-qty" inputmode="numeric" placeholder="Qty" value="'+esc(row.qty||'1')+'">'+
    '<input class="set-in lr-hours" inputmode="decimal" placeholder="Hrs/day" value="'+esc(row.hours_per_day||'')+'">'+
    '<label style="font-size:12px;white-space:nowrap"><input type="checkbox" class="lr-crit"'+(row.critical?' checked':'')+'> backup</label>'+
    '<button class="btn" title="Remove" onclick="this.parentNode.remove()">✕</button>';
  c.appendChild(d);
}

function advGatherAnswers(){
  var a={};
  (ADV.schema.sections||[]).forEach(function(sec){ (sec.fields||[]).forEach(function(f){
    if(f.type==='loads'){
      var loads=[]; document.querySelectorAll('#advLoads .advLoadRow').forEach(function(r){
        var w=(r.querySelector('.lr-watts')||{}).value||''; if(!w) return;
        loads.push({ name:(r.querySelector('.lr-name')||{}).value||'', watts:w,
          qty:(r.querySelector('.lr-qty')||{}).value||'1', hours_per_day:(r.querySelector('.lr-hours')||{}).value||'',
          critical:(r.querySelector('.lr-crit')||{}).checked?1:0 });
      });
      a[f.key]=loads;
    } else {
      var el=document.getElementById('adv_'+f.key); if(el && el.value!=='') a[f.key]=el.value;
    }
  }); });
  return a;
}

async function advCalculate(){
  var r=await post('/papi/advisor/compute',{ profile:'solar', answers:advGatherAnswers() });
  if(!r||!r.ok){ toast('Could not calculate', true); return; }
  advRenderResult(r.sizing);
}
function advRenderResult(sizing){
  var r=(sizing&&sizing.result)||{};
  function row(l,v){ return '<div class="r"><span class="l">'+esc(l)+'</span><span class="v">'+esc(v)+'</span></div>'; }
  var el=document.getElementById('advResult'); if(!el) return;
  el.innerHTML='<div class="card"><div class="h">'+icon('advisor','ic-sm')+'Recommended system</div><div class="b">'+
    row('Daily energy', advN(r.daily_energy_kwh)+' kWh/day')+
    row('Connected load', advN(r.connected_load_w)+' W')+
    row('Peak demand', advN(r.peak_demand_w)+' W')+
    row('Solar array', advN(r.pv_array_w)+' W'+(r.panel_count?(' ('+r.panel_count+' panels)'):''))+
    row('Battery bank', advN(r.battery_bank_wh)+' Wh · '+advN(r.battery_bank_ah)+' Ah')+
    row('Inverter', advN(r.inverter_va)+' VA ('+advN(r.inverter_w)+' W)')+
    '</div></div>';
}

async function advSave(){
  var a=advGatherAnswers();
  var r=await post('/papi/advisor/projects',{ profile:'solar', name:(a.customer_type||'Solar project'), answers:a });
  if(!r||!r.ok){ toast('Could not save', true); return; }
  toast('Project saved');
  route('advisor', r.project.id);
}

async function advisorOpenProject(id){
  document.getElementById('view').innerHTML=skeleton();
  var r=await getQuery('/papi/advisor/projects/'+id,{});
  if(!r||!r.project){ document.getElementById('view').innerHTML=emptyState('bolt','Not found','This project could not be loaded.'); return; }
  var p=r.project; ADV.projectId=p.id;
  document.getElementById('view').innerHTML=
    '<div class="crumb" style="cursor:pointer" onclick="route(\'advisor\')">Solar Advisor</div>'+
    '<div class="rec-head"><div class="rec-av" style="background:#d97706">'+icon('advisor','ic-lg')+'</div>'+
    '<div class="idn"><h1>'+esc(p.name||'Solar project')+' '+statusBadge(p.status)+'</h1>'+
    '<div class="sub">'+esc((p.answers&&p.answers.customer_type)||'')+((p.answers&&p.answers.location)?(' · '+esc(p.answers.location)):'')+'</div></div></div>'+
    '<div id="advResult"></div><div id="advEquip"></div><div id="advProposal"></div>';
  advRenderResult(p.sizing||{});
  advRenderEquipEditor();
  if(p.proposal) advRenderProposal(p.proposal);
}

function advRenderEquipEditor(){
  var el=document.getElementById('advEquip'); if(!el) return;
  el.innerHTML='<div class="card"><div class="h">'+icon('billing','ic-sm')+'Equipment &amp; commercial offer</div><div class="b">'+
    '<div class="mut" style="font-size:12px;margin-bottom:8px">Add the equipment lines for the proposal (name, quantity, unit price).</div>'+
    '<div id="advEquipRows"></div>'+
    '<button class="btn" style="margin-top:8px" onclick="advAddEquip()">+ Add equipment</button>'+
    '<div style="margin-top:12px"><button class="btn pri" onclick="advGenerateProposal()">Generate proposal</button></div>'+
    '</div></div>';
  advAddEquip();
}
function advAddEquip(row){
  row=row||{}; var c=document.getElementById('advEquipRows'); if(!c) return;
  var d=document.createElement('div'); d.className='advEquipRow';
  d.style.cssText='display:grid;grid-template-columns:2fr .8fr 1.3fr auto;gap:6px;margin-bottom:6px';
  d.innerHTML='<input class="set-in eq-name" placeholder="Item" value="'+esc(row.name||'')+'">'+
    '<input class="set-in eq-qty" inputmode="numeric" placeholder="Qty" value="'+esc(row.qty||'1')+'">'+
    '<input class="set-in eq-price" inputmode="decimal" placeholder="Unit price" value="'+esc(row.unit_price||'')+'">'+
    '<button class="btn" title="Remove" onclick="this.parentNode.remove()">✕</button>';
  c.appendChild(d);
}
function advGatherEquip(){
  var out=[]; document.querySelectorAll('#advEquipRows .advEquipRow').forEach(function(r){
    var name=(r.querySelector('.eq-name')||{}).value||''; if(!name) return;
    out.push({ name:name, qty:(r.querySelector('.eq-qty')||{}).value||'1', unit_price:(r.querySelector('.eq-price')||{}).value||'0' });
  }); return out;
}
async function advGenerateProposal(){
  if(!ADV.projectId){ toast('Save the project first', true); return; }
  var r=await post('/papi/advisor/projects/'+ADV.projectId+'/proposal',{ equipment:advGatherEquip(), opts:{} });
  if(!r||!r.ok){ toast('Could not generate proposal', true); return; }
  advRenderProposal(r.proposal); toast('Proposal generated');
  var el=document.getElementById('advProposal'); if(el) el.scrollIntoView({behavior:'smooth'});
}

function advRenderProposal(pr){
  var el=document.getElementById('advProposal'); if(!el||!pr) return;
  var cur=pr.currency||'UGX';
  function money(v){ return cur+' '+advN(v); }
  var body=(pr.sections||[]).map(function(s){
    var inner='';
    if(s.body) inner+='<p style="margin:0 0 8px;line-height:1.55">'+esc(s.body)+'</p>';
    if(s.rows){ inner+=s.rows.map(function(r){ var v=r.value; if(typeof v==='number') v=money(v); return '<div class="r"><span class="l">'+esc(r.label)+'</span><span class="v">'+esc(v)+'</span></div>'; }).join(''); }
    if(s.key==='equipment_list' && s.items){
      inner+='<table style="width:100%;border-collapse:collapse;font-size:13px"><tr style="color:var(--faint);text-align:left"><th>Item</th><th style="text-align:center">Qty</th><th style="text-align:right">Unit</th><th style="text-align:right">Total</th></tr>'+
        s.items.map(function(it){ return '<tr><td style="padding:4px 0">'+esc(it.name)+(it.sku?(' <span class="mut">'+esc(it.sku)+'</span>'):'')+'</td><td style="text-align:center">'+esc(it.qty)+'</td><td style="text-align:right">'+money(it.unit_price)+'</td><td style="text-align:right">'+money(it.line_total)+'</td></tr>'; }).join('')+'</table>'+
        (s.subtotal!=null?('<div class="r" style="margin-top:6px"><span class="l">Subtotal</span><span class="v">'+money(s.subtotal)+'</span></div>'):'')+
        (s.note?('<div class="mut" style="font-size:12px">'+esc(s.note)+'</div>'):'');
    } else if(s.groups){
      inner+=s.groups.map(function(g){ return '<div style="margin-bottom:10px"><b>'+esc(g.product)+'</b>'+(g.specs||[]).map(function(sp){ return '<div class="r"><span class="l">'+esc(sp.label)+'</span><span class="v">'+esc(sp.value)+'</span></div>'; }).join('')+'</div>'; }).join('');
    } else if(s.key==='warranty' && s.items){
      inner+=s.items.map(function(w){ return '<div class="r"><span class="l">'+esc(w.product)+'</span><span class="v">'+esc(w.warranty)+'</span></div>'; }).join('');
    } else if(s.items){
      inner+='<ul style="margin:0;padding-left:18px;line-height:1.55">'+s.items.map(function(x){ return '<li>'+esc(typeof x==='string'?x:(x.label||JSON.stringify(x)))+'</li>'; }).join('')+'</ul>';
    }
    if(s.key==='commercial_offer' && s.total!=null) inner+='<div class="r" style="margin-top:6px;font-weight:700"><span class="l">Total</span><span class="v">'+money(s.total)+'</span></div>';
    return '<div class="card"><div class="h">'+esc(s.title)+'</div><div class="b">'+(inner||'<span class="mut">—</span>')+'</div></div>';
  }).join('');
  el.innerHTML='<div class="rec-head" style="margin-top:18px"><div class="idn"><div class="crumb">Proposal</div></div>'+
    '<div class="acts inl" style="margin-left:auto"><button class="btn" onclick="advDownloadPdf()">Download PDF</button></div></div>'+body;
}
function advDownloadPdf(){
  if(!ADV.projectId){ toast('Save the project first', true); return; }
  window.open('/papi/advisor/projects/'+ADV.projectId+'/proposal/pdf','_blank');
}

/* Compact number formatter for the advisor screens (thousands separators, ≤2 decimals). */
function advN(v){ v=Number(v||0); return (Math.round(v*100)/100).toLocaleString('en-US',{maximumFractionDigits:2}); }

function renderSoon(key){
  const s=SOON[key]; const c=CFG.counts[NAVCOUNT[key]||key];
  document.getElementById('view').innerHTML=
    '<div class="crumb">'+(LABELS[key]||key)+'</div>'+
    '<div class="card"><div class="b" style="text-align:center;padding:56px 24px">'+
    '<div class="eic" style="width:56px;height:56px;margin:0 auto 16px">'+icon(key,'ic-lg')+'</div>'+
    '<h1 style="font-size:20px;margin-bottom:6px">'+(LABELS[key]||key)+(c!=null?' <span class="mut" style="font-weight:600">('+fmtCount(c)+')</span>':'')+'</h1>'+
    '<div class="mut" style="max-width:420px;margin:0 auto 18px">This dedicated list view is next in the build. In the meantime, open the related workspace to work these records.</div>'+
    '<button class="btn pri" onclick="route(\''+s.to+'\')">Open '+s.toLabel+'</button>'+
    '</div></div>';
}
function refresh(){ if(CUR.key) route(CUR.key, CUR.id); }
/* Mobile nav drawer + top-bar account menu (Sign out / classic panel). */
function toggleNav(){ const a=document.querySelector('.app'); const open=a.classList.toggle('navopen'); document.getElementById('scrim').classList.toggle('on',open); }
/* Mobile quick-add sheet (the center + in the bottom nav). Each item jumps to its workspace. */
function closeQuickAdd(){ document.getElementById('qsheet').classList.remove('on'); }
function quickAdd(key){ closeQuickAdd();
  // The "+" opens a real modern form for the quick creates, instead of just dropping you on a list.
  if(key==='task.new'){ taskSheetOpen(); return; }
  if(key==='customers' && isAppUI()){ custSheetOpen(); return; }
  if(key==='cashbook' && isAppUI()){ expSheetOpen(); return; }
  if(key==='invoices' && isAppUI()){ bosQuickInvoice(); return; }
  route(key);
}
/* ===== New Task (design v1.0) — assign work to a staff member from the phone. POST /papi/task/assign
   (owner/manager only). Reuses the assignable-users endpoint for the picker. ===== */
var TASK_STAFF=[], TASK_PRIO=2;
async function taskSheetOpen(){
  var r=null; try{ r=await getQuery('/papi/task/assignable',{}); }catch(e){}
  if(!r || r.ok===false){ toast((r&&r.error==='forbidden')?'Only an owner or manager can assign tasks':'Could not load staff', true); return; }
  TASK_STAFF=r.users||[]; TASK_PRIO=2;
  if(!TASK_STAFF.length){ toast('Add a staff member first (Team settings)', true); return; }
  var opts=TASK_STAFF.map(function(u){ return '<option value="'+u.id+'">'+esc(u.name)+(u.role?(' · '+esc(u.role)):'')+'</option>'; }).join('');
  fsheetOpen('<div class="grab"></div><div class="pay-h">New task</div><div class="pay-sub">Assign work to your team</div>'
    +'<div class="fs-field"><label>Task</label><input id="tkTitle" class="set-in" placeholder="e.g. Update counter-book photos"></div>'
    +'<div class="fs-field"><label>Assign to</label><select id="tkWho" class="set-in">'+opts+'</select></div>'
    +'<div class="fs-field"><label>Due (optional)</label><input id="tkDue" class="set-in" type="datetime-local"></div>'
    +'<div class="fs-field"><label>Priority</label><div class="set-segs" id="tkPrio">'
      + [['1','Low'],['2','Normal'],['3','High']].map(function(p){ return '<button class="set-seg'+(p[0]==='2'?' on':'')+'" data-v="'+p[0]+'" onclick="taskPrio(this)">'+p[1]+'</button>'; }).join('')
    +'</div></div>'
    +'<button class="pay-go" onclick="taskSave(this)">Assign task</button><button class="pay-cancel" onclick="fsheetClose()">Cancel</button>');
  setTimeout(function(){ var e=document.getElementById('tkTitle'); if(e) e.focus(); },80);
}
function taskPrio(btn){ TASK_PRIO=Number(btn.getAttribute('data-v'))||2; var p=btn.parentNode; p.querySelectorAll('.set-seg').forEach(function(x){ x.classList.remove('on'); }); btn.classList.add('on'); }
async function taskSave(btn){
  var title=((document.getElementById('tkTitle')||{}).value||'').trim();
  var who=Number((document.getElementById('tkWho')||{}).value||0);
  var due=(document.getElementById('tkDue')||{}).value||'';
  if(!title){ toast('Enter the task', true); return; }
  if(!who){ toast('Pick who to assign to', true); return; }
  if(btn){ btn.disabled=true; btn.textContent='Assigning…'; }
  var body={ assignee_id:who, title:title, priority:TASK_PRIO };
  if(due) body.due_at=due;
  var r=null,neterr=false; try{ r=await post('/papi/task/assign', body); }catch(e){ neterr=true; }
  if(btn){ btn.disabled=false; btn.textContent='Assign task'; }
  if(!neterr && r && r.ok){ fsheetClose(); toast('Task assigned'); if(CUR&&CUR.key==='tasks') route('tasks'); }
  else { toast((r&&(r.message||r.error==='forbidden'?'Only an owner or manager can assign tasks':r.error))||'Could not assign the task', true); }
}
/* ===== Quick-add create sheets (design v1.0) — bottom-sheet forms that post to real endpoints. ===== */
function fsheetEnsure(){ var s=document.getElementById('formsheet'); if(!s){ s=document.createElement('div'); s.id='formsheet'; s.className='qsheet-ov'; s.onclick=function(e){ if(e.target===s) fsheetClose(); }; document.body.appendChild(s); } return s; }
function fsheetClose(){ var s=document.getElementById('formsheet'); if(s) s.classList.remove('on'); }
function fsheetOpen(inner){ var s=fsheetEnsure(); s.innerHTML='<div class="qsheet paysheet" role="dialog">'+inner+'</div>'; s.classList.add('on'); }
function custSheetOpen(){
  fsheetOpen('<div class="grab"></div><div class="pay-h">New customer</div><div class="pay-sub">Add a customer to your book</div>'
    +'<div class="fs-field"><label>Name</label><input id="custName" class="set-in" placeholder="e.g. Adel Al-Jili"></div>'
    +'<div class="fs-field"><label>Phone</label><input id="custPhone" class="set-in" inputmode="tel" placeholder="e.g. 211 900 000"></div>'
    +'<div class="fs-field"><label>City (optional)</label><input id="custCity" class="set-in" placeholder="e.g. Juba"></div>'
    +'<button class="pay-go" onclick="custSave(this)">Add customer</button><button class="pay-cancel" onclick="fsheetClose()">Cancel</button>');
  setTimeout(function(){ var e=document.getElementById('custName'); if(e) e.focus(); },80);
}
async function custSave(btn){
  var name=((document.getElementById('custName')||{}).value||'').trim();
  var phone=((document.getElementById('custPhone')||{}).value||'').trim();
  var city=((document.getElementById('custCity')||{}).value||'').trim();
  if(!name){ toast('Enter a name', true); return; }
  if(!phone.replace(/[^0-9]/g,'')){ toast('Enter a phone number', true); return; }
  if(btn){ btn.disabled=true; btn.textContent='Adding…'; }
  var r=null,neterr=false; try{ r=await getQuery('/papi/customer-save',{name:name, phone:phone, address:city}); }catch(e){ neterr=true; }
  if(btn){ btn.disabled=false; btn.textContent='Add customer'; }
  if(!neterr && r && r.ok){ fsheetClose(); toast('Customer added'); if(CUR&&CUR.key==='customers') bosCustomers(); }
  else { toast((r&&(r.message||r.error==='phone_required'?'A phone number is required':r.error))||'Could not add customer', true); }
}
var _EXP={cur:'USD', cat:''};
function expSheetOpen(){
  var base=(CFG.currency&&CFG.currency.base)||'USD'; _EXP={cur:base, cat:''};
  var opts=['USD','UGX','SSP'], cats=['Transport','Rent','Salaries','Utilities','Supplies','Airtime','Other'];
  fsheetOpen('<div class="grab"></div><div class="pay-h">New expense</div><div class="pay-sub">Cash out — records to your cash book</div>'
    +'<div class="pay-amtrow"><span class="pay-cur" id="expCurLbl">'+esc(base)+'</span><input id="expAmt" class="pay-amt" type="number" inputmode="decimal" min="0" step="0.01" placeholder="0.00"></div>'
    +'<div class="fs-field"><label>Currency</label><div class="set-segs">'+opts.map(function(o){return '<button class="set-seg'+(o===base?' on':'')+'" onclick="expSetCur(this,\''+o+'\')">'+o+'</button>';}).join('')+'</div></div>'
    +'<div class="fs-field"><label>Category (optional)</label><div class="set-segs fs-wrap">'+cats.map(function(c){return '<button class="set-seg fs-cat" onclick="expSetCat(this,\''+esc(c)+'\')">'+esc(c)+'</button>';}).join('')+'</div></div>'
    +'<div class="fs-field"><label>Note (optional)</label><input id="expNote" class="set-in" placeholder="What was it for?"></div>'
    +'<button class="pay-go" onclick="expSave(this)">Record expense</button><button class="pay-cancel" onclick="fsheetClose()">Cancel</button>');
}
function expSetCur(btn,c){ _EXP.cur=c; var p=btn.parentNode; p.querySelectorAll('.set-seg').forEach(function(x){ x.classList.remove('on'); }); btn.classList.add('on'); var l=document.getElementById('expCurLbl'); if(l) l.textContent=c; }
function expSetCat(btn,c){ var was=btn.classList.contains('on'); var p=btn.parentNode; p.querySelectorAll('.set-seg').forEach(function(x){ x.classList.remove('on'); }); if(!was){ btn.classList.add('on'); _EXP.cat=c; } else { _EXP.cat=''; } }
async function expSave(btn){
  var amt=Math.round((parseFloat((document.getElementById('expAmt')||{}).value||0))*100)/100;
  if(!(amt>0)){ toast('Enter an amount', true); return; }
  var note=((document.getElementById('expNote')||{}).value||'').trim();
  if(btn){ btn.disabled=true; btn.textContent='Recording…'; }
  var r=null,neterr=false;
  try{ r=await post('/papi/trade-cashbook',{dir:'out', native_amount:amt, currency:_EXP.cur, category:_EXP.cat, note:note}); }catch(e){ neterr=true; }
  if(btn){ btn.disabled=false; btn.textContent='Record expense'; }
  if(!neterr && r && r.ok){ fsheetClose(); toast('Expense recorded'); if(CUR&&CUR.key==='ws.finance') bosFinance(); }
  else { toast((r&&(r.message||r.error))||'Could not record the expense', true); }
}

/* ===== BOS Slice 2 — Customer Timeline: one customer's whole history from the event spine ===== */
/* ===== Guided Collect Run — the app as an assistant, offline-safe (reuses the OB outbox + /papi/payments) ===== */
var RUN={stops:[],i:0,collected:0,count:0,total:0,cur:'USD'}, RUN_METHOD='Cash';
function runEnsure(){ if(!document.getElementById('runview')){ var d=document.createElement('div'); d.id='runview'; d.className='runview'; document.body.appendChild(d); } }
async function runStart(){
  runEnsure(); var el=document.getElementById('runview'); el.classList.add('on'); el.innerHTML='<div class="run-load">Loading your run…</div>';
  var r=null; try{ r=await getQuery('/papi/bos-run',{}); }catch(e){}
  if(!r || r.ok===false || !(r.stops&&r.stops.length)){ el.innerHTML='<div class="run-load">Nothing to collect right now.<br><button class="run-x2" onclick="runExit()">Close</button></div>'; return; }
  RUN={stops:r.stops, i:0, collected:0, count:0, total:Number(r.total||0), cur:r.currency||'USD'}; RUN_METHOD='Cash';
  runRender();
}
function runXIcon(){ return '<svg viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg>'; }
function runDots(){ var h=''; for(var k=0;k<RUN.stops.length;k++){ h+='<i class="'+(k<RUN.i?'done':(k===RUN.i?'on':''))+'"></i>'; } return h; }
function runTop(closeable){ return '<div class="run-top"><div class="run-dots">'+runDots()+'</div>'+(closeable?'<button class="run-x" onclick="runExit()">'+runXIcon()+'</button>':'')+'</div>'; }
function runRender(){
  var el=document.getElementById('runview');
  if(RUN.i>=RUN.stops.length){
    el.innerHTML=runTop(true)+'<div class="run-done"><div class="run-check"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></div>'
      +'<div class="run-done-v">Run complete</div><div class="run-done-k">'+RUN.cur+' '+bmoney0(RUN.collected)+' collected from '+RUN.count+' of '+RUN.stops.length+' stops</div>'
      +'<button class="run-back" onclick="runExit()">Back to Today</button></div>'; return;
  }
  var s=RUN.stops[RUN.i], ccy=s.currency||RUN.cur;
  var dot = s.status==='overdue'?'#E24B3B':(s.status==='due'?'#DD8500':'#12A150');
  el.innerHTML=runTop(true)+'<div class="run-k">Stop '+(RUN.i+1)+' of '+RUN.stops.length+'</div>'
    +'<div class="run-card">'
    +'<div class="run-cust"><div class="run-av" style="background:'+avColor(s.name)+'">'+esc(initials(s.name))+'</div>'
      +'<div><div class="run-nm">'+esc(s.name)+'</div><div class="run-mt"><span style="background:'+dot+'"></span>'+esc(s.invoice_no||'')+(s.due_label?' · '+esc(s.due_label):'')+'</div></div></div>'
    +'<div class="run-aml">To collect</div>'
    +'<div class="run-amt-row"><span class="run-cur">'+esc(ccy)+'</span><input id="runamt" class="run-in" inputmode="decimal" value="'+Number(s.amount).toFixed(2)+'"></div>'
    +(s.city?'<div class="run-nav" onclick="runMaps('+RUN.i+')"><svg class="run-pin" viewBox="0 0 24 24"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>'+esc(s.city)+'<span class="run-go">Navigate ›</span></div>':'')
    +'<div class="run-seg" onclick="runSeg(event)"><button class="on" data-m="Cash">Cash</button><button data-m="MoMo">MoMo</button><button data-m="Bank">Bank</button></div>'
    +'<div class="run-act"><button class="run-skip" onclick="runSkip()">Skip</button><button class="run-col" onclick="runCollect()">Collect '+esc(ccy)+' '+bmoney0(s.amount)+'</button></div>'
    +'</div>';
}
function runSeg(e){ var b=e.target.closest('button'); if(!b)return; b.parentNode.querySelectorAll('button').forEach(function(x){x.classList.remove('on')}); b.classList.add('on'); RUN_METHOD=b.dataset.m||'Cash'; }
function runMaps(i){ var s=RUN.stops[i]; try{ window.open('https://www.google.com/maps/search/?api=1&query='+encodeURIComponent((s.city||'')+' '+(s.name||'')),'_blank'); }catch(e){} }
async function runCollect(){
  var s=RUN.stops[RUN.i], el=document.getElementById('runamt');
  var amt=Math.round((parseFloat(el&&el.value)||0)*100)/100; if(!(amt>0)){ toast('Enter an amount',true); return; }
  var id=OB.uuid(), now=new Date();
  var item={ id:id, ts:now.getTime(), kind:'collect', customer_id:Number(s.customer_id||0), endpoint:'/papi/payments',
    body:{ customer_name:s.name, customer_phone:s.phone, order_no:s.order_no, invoice_no:s.invoice_no, kind:'settlement',
      currency:(s.currency||RUN.cur), amount:amt, method:RUN_METHOD, rate_to_usd:0, discount:0, idempotency_key:id, created_date:now.toISOString() },
    label:('Collect '+(s.currency||RUN.cur)+' '+bmoney0(amt)) };
  var synced=false;
  if(navigator.onLine){ try{ var rr=await post(item.endpoint,item.body); synced=!!(rr&&rr.ok); }catch(e){} }
  if(!synced){ try{ await OB.add(item); await obBadge(); }catch(e){} }
  RUN.collected+=amt; RUN.count++; RUN_METHOD='Cash';
  var el2=document.getElementById('runview');
  el2.innerHTML=runTop(false)+'<div class="run-ok"><div class="run-check"><svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></div>'
    +'<div class="run-ok-v">'+(s.currency||RUN.cur)+' '+bmoney0(amt)+'</div>'
    +'<div class="run-ok-k">'+(synced?'Payment recorded · receipt on WhatsApp':'Saved offline — will sync')+'</div>'
    +'<button class="run-next" onclick="runAdvance()">'+((RUN.i+1)<RUN.stops.length?'Next stop':'Finish')+' ›</button></div>';
}
function runAdvance(){ RUN.i++; runRender(); }
/* Skip needs a reason + a consequence: capture why, log an offline-safe follow-up, then move on. */
function runSkip(){
  var s=RUN.stops[RUN.i], el=document.getElementById('runview');
  var reasons=['Shop closed','Owner away','Will pay later','Amount disputed','Other'];
  el.innerHTML=runTop(true)+'<div class="run-k">Stop '+(RUN.i+1)+' of '+RUN.stops.length+'</div>'
    +'<div class="run-card"><div class="run-skip-h">Why skip '+esc(s.name)+'?</div>'
    +'<div class="run-skip-s">We’ll log a follow-up so it isn’t forgotten.</div>'
    +'<div class="run-reasons">'+reasons.map(function(r){ return '<button onclick="runSkipDo('+JSON.stringify(r).replace(/"/g,'&quot;')+')">'+esc(r)+'</button>'; }).join('')+'</div>'
    +'<button class="run-skip-back" onclick="runRender()">← Back to collect</button></div>';
}
function runSkipDo(reason){
  var s=RUN.stops[RUN.i], cid=Number(s.customer_id||0);
  if(cid>0){ try{
    var id=OB.uuid(), now=new Date();
    var item={ id:id, ts:now.getTime(), kind:'followup', customer_id:cid, endpoint:'/papi/customer-note',
      body:{ customer_id:cid, kind:'followup',
        text:'Skipped on collection run — '+reason+' ('+(s.currency||RUN.cur)+' '+bmoney0(s.amount)+' still due)',
        idempotency_key:id, occurred_at:now.toISOString(), connectivity:(navigator.onLine?'online':'offline') } };
    if(navigator.onLine){ post(item.endpoint,item.body).then(function(r){ if(!(r&&r.ok)) OB.add(item); }).catch(function(){ OB.add(item); }); }
    else { OB.add(item); }
    if(typeof obBadge==='function') obBadge();
  }catch(e){} }
  toast('Follow-up logged — '+reason);
  RUN.i++; runRender();
}
function runExit(){ var el=document.getElementById('runview'); if(el){ el.classList.remove('on'); el.innerHTML=''; }
  if(RUN.count>0){ window._RUN_DONE={amt:RUN.collected,count:RUN.count,cur:RUN.cur}; }
  try{ if(CUR&&CUR.key==='bos.today') bosToday(); }catch(e){} }
/* Modern Operations hub (design v1.0): action-first urgency rows + browse grid, from the app's own counts. */
function bosOperations(){
  const v=document.getElementById('view');
  const cnt=function(k){ var c=(CFG.counts)?CFG.counts[(NAVCOUNT[k]||k)]:null; return (c==null||isNaN(Number(c)))?null:Number(c); };
  const IC={
    truck:'<path d="M14 18V6a2 2 0 0 0-2-2H2v12M14 9h4l4 4v3h-8"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
    alert:'<path d="M12 9v4M12 17h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
    box:'<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
    bag:'<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
    user:'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    tag:'<path d="M20.6 13.4 12 22l-9-9V3h10z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
    check:'<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>'
  };
  function urow(key,ic,tone,title,sub){
    if(!hasScreen(key)) return '';
    var n=cnt(key);
    var right = (n==null)
      ? '<svg class="i ops-chev" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>'
      : '<div class="ops-n '+(n>0?tone:'zero')+'">'+n+'</div>';
    return '<div class="ops-row" onclick="route(\''+key+'\')"><div class="ops-ic '+tone+'"><svg class="i" viewBox="0 0 24 24">'+ic+'</svg></div>'
      +'<div class="ops-bd"><div class="ops-t">'+title+'</div><div class="ops-s">'+sub+'</div></div>'+right+'</div>';
  }
  const actions = urow('tasks',IC.check,'indigo','Tasks','assign &amp; track team work')
    + urow('dispatch',IC.truck,'sky','Deliveries to make','dispatch &amp; drop-off')
    + urow('warehouse',IC.alert,'red','Low stock','reorder before it runs out')
    + urow('salesOrders',IC.box,'indigo','Orders to fulfil','pick, pack &amp; send')
    + urow('purchase.process',IC.bag,'amber','Purchases to receive','from suppliers');
  function tile(key,ic,title){ if(!hasScreen(key)) return ''; var n=cnt(key);
    return '<div class="ops-tile" onclick="route(\''+key+'\')"><div class="ops-tic"><svg class="i" viewBox="0 0 24 24">'+ic+'</svg></div>'
      +'<div class="ops-tv">'+(n==null?'—':n)+'</div><div class="ops-tl">'+title+'</div></div>'; }
  const grid='<div class="ops-grid">'+tile('products',IC.box,'Products')+tile('suppliers',IC.user,'Suppliers')+tile('categories',IC.tag,'Categories')+'</div>';
  let html='<div class="bcl-h"><div class="bcl-title">Operations</div></div>';
  if(actions) html+='<div class="c360-sec"><h3>Needs action</h3></div>'+actions;
  html+='<div class="c360-sec"><h3>Browse</h3></div>'+grid;
  v.innerHTML='<div class="mtoday">'+html+'</div>';
}
/* Modern Finance hub (design v1.0): cash-on-hand hero (SSOT, per currency) + real section amounts. */
function finFmt(n){ return Number(n||0).toLocaleString(undefined,{maximumFractionDigits:0}); }
async function bosFinance(){
  const v=document.getElementById('view'); v.innerHTML=skeleton();
  let r; try{ r=await getQuery('/papi/bos-finance',{}); }catch(e){ r=null; }
  if(!r || r.ok===false){ v.innerHTML=emptyState('dollar','Finance unavailable','The finance overview could not load right now.'); return; }
  const cash=r.cash||{}, others=(cash.others||[]), cur=r.currency||'USD';
  const subs = others.length ? others.map(function(o){ return esc(o.ccy)+' '+finFmt(o.amount); }).join('  ·  ') : 'USD cash only';
  const hero='<div class="fin-hero"><div class="fin-hk">Cash on hand</div><div class="fin-hv">USD '+finFmt(cash.usd)+'</div><div class="fin-hs">'+subs+'</div></div>';
  const IC={
    coin:'<circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5h4a1.5 1.5 0 0 1 0 3h-3a1.5 1.5 0 0 0 0 3h4"/>',
    doc:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
    card:'<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/>',
    book:'<path d="M4 5a2 2 0 0 1 2-2h13v18H6a2 2 0 0 0-2 2z"/><path d="M4 5v14"/>',
    chart:'<path d="M3 3v18h18"/><path d="M7 14l4-4 3 3 5-6"/>'
  };
  function row(ic,bg,fg,t,sub,amt,key){
    return '<div class="fin-c" onclick="route(\''+key+'\')"><div class="fin-ic" style="background:'+bg+';color:'+fg+'"><svg class="i" viewBox="0 0 24 24">'+ic+'</svg></div>'
      +'<div class="fin-bd"><div class="fin-t">'+esc(t)+'</div><div class="fin-s">'+esc(sub)+'</div></div>'
      +'<div class="fin-r">'+(amt!=null?'<b>'+esc(amt)+'</b>':'')+'<svg class="i fin-chev" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></div></div>';
  }
  const list='<div class="fin-list">'
    +row(IC.coin,'#FCF1DE','#DD8500','Collections','money to collect',cur+' '+finFmt(r.to_collect),'collections')
    +row(IC.doc,'#EDEBFB','#6C5CE7','Invoices',(r.invoices||0)+' open',null,'invoices')
    +row(IC.card,'#E4F6EC','#0A7A3C','Payments','received this month',cur+' '+finFmt(r.payments),'payments')
    +row(IC.book,'#E7F3FC','#2C97DE','Cash book','cash in & out',null,'cashbook')
    +row(IC.chart,'#EAECEF','#0C1420','Profit & Loss','this month',cur+' '+finFmt(r.pnl_month),'pnl')
    +'</div>';
  v.innerHTML='<div class="mtoday"><div class="bcl-h"><div class="bcl-title">Finance</div></div>'+hero+list+'</div>';
}
/* ===== Modern Receive-Payment screen (design v1.0) — replaces the payments-manager iframe on phones.
   Pick an open invoice → amount + Cash/MoMo/Bank → Record. Posts to the ONE recorder (/papi/payments),
   idempotent + offline-safe (same path as the Collect Run). Also lists recent receipts (GET /papi/payments). */
var PAYLIST=[], PAY_SEL=null, PAY_METHOD='Cash';
var PAY_COIN='<circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5h4a1.5 1.5 0 0 1 0 3h-3a1.5 1.5 0 0 0 0 3h4"/>';
var PAY_CARD='<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/>';
async function bosPayments(){
  const v=document.getElementById('view'); v.innerHTML=skeleton();
  let run=null, recv=null;
  try{ run=await getQuery('/papi/bos-run',{}); }catch(e){}
  try{ recv=await getQuery('/papi/payments',{}); }catch(e){}
  PAYLIST=(run&&run.stops)||[];
  const head='<div class="bcl-h"><div class="bcl-title">Receive Payment</div></div>';
  let open;
  if(PAYLIST.length){
    open='<div class="c360-sec"><h3>Open invoices · tap to collect</h3></div><div class="fin-list">'
      + PAYLIST.map(payRow).join('') + '</div>';
  } else {
    open='<div class="btl-empty">No open invoices right now — everyone is settled.</div>';
  }
  const rows=(recv&&recv.payments)||[];
  let recent='';
  if(rows.length){
    recent='<div class="c360-sec"><h3>Recent receipts</h3></div><div class="fin-list">'
      + rows.slice(0,15).map(payReceiptRow).join('') + '</div>';
  }
  v.innerHTML='<div class="mtoday">'+head+open+recent+'</div>';
}
function payRow(s,i){
  return '<div class="fin-c" onclick="payOpen('+i+')"><div class="fin-ic" style="background:#FCF1DE;color:#DD8500"><svg class="i" viewBox="0 0 24 24">'+PAY_COIN+'</svg></div>'
    +'<div class="fin-bd"><div class="fin-t">'+esc(s.name||'Customer')+'</div><div class="fin-s">'+esc(s.invoice_no||s.order_no||'')+(s.city?(' · '+esc(s.city)):'')+'</div></div>'
    +'<div class="fin-r"><b>'+esc(s.currency||'USD')+' '+finFmt(s.amount)+'</b><svg class="i fin-chev" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></div></div>';
}
function payReceiptRow(p){
  return '<div class="fin-c" style="cursor:default"><div class="fin-ic" style="background:#E4F6EC;color:#0A7A3C"><svg class="i" viewBox="0 0 24 24">'+PAY_CARD+'</svg></div>'
    +'<div class="fin-bd"><div class="fin-t">'+esc(p.customer||'—')+'</div><div class="fin-s">'+esc(p.receipt_no||'')+' · '+esc(p.method||'Cash')+' · '+esc((p.date||'').slice(0,10))+'</div></div>'
    +'<div class="fin-r"><b>'+esc(p.currency||'USD')+' '+finFmt(p.amount)+'</b></div></div>';
}
function payEnsure(){ var s=document.getElementById('paysheet'); if(!s){ s=document.createElement('div'); s.id='paysheet'; s.className='qsheet-ov'; s.onclick=function(e){ if(e.target===s) payClose(); }; document.body.appendChild(s); } return s; }
function payOpen(i){
  PAY_SEL=i; PAY_METHOD='Cash'; var s=PAYLIST[i]; if(!s) return;
  var sheet=payEnsure();
  sheet.innerHTML='<div class="qsheet paysheet" role="dialog" aria-label="Receive payment"><div class="grab"></div>'
    +'<div class="pay-h">Receive from '+esc(s.name||'Customer')+'</div>'
    +'<div class="pay-sub">'+esc(s.invoice_no||s.order_no||'')+' · '+esc(s.due_label||'balance due')+' · works offline</div>'
    +'<div class="pay-amtrow"><span class="pay-cur">'+esc(s.currency||'USD')+'</span><input id="payamt" class="pay-amt" type="number" inputmode="decimal" min="0" step="0.01" value="'+Number(s.amount||0).toFixed(2)+'"></div>'
    +'<div class="pay-methods">'+['Cash','MoMo','Bank'].map(function(m){ return '<button class="paym'+(m==='Cash'?' on':'')+'" onclick="paySetMethod(this,\''+m+'\')">'+m+'</button>'; }).join('')+'</div>'
    +'<button class="pay-go" onclick="payRecord()">Record payment</button>'
    +'<button class="pay-cancel" onclick="payClose()">Cancel</button></div>';
  sheet.classList.add('on');
}
function paySetMethod(btn,m){ PAY_METHOD=m; var p=btn.parentNode; p.querySelectorAll('.paym').forEach(function(x){ x.classList.remove('on'); }); btn.classList.add('on'); }
function payClose(){ var s=document.getElementById('paysheet'); if(s) s.classList.remove('on'); }
async function payRecord(){
  if(PAY_SEL==null) return; var s=PAYLIST[PAY_SEL]; if(!s) return;
  var el=document.getElementById('payamt'); var amt=Math.round((parseFloat(el&&el.value)||0)*100)/100;
  if(!(amt>0)){ toast('Enter an amount', true); return; }
  var id=OB.uuid(), now=new Date();
  var item={ id:id, ts:now.getTime(), kind:'collect', customer_id:Number(s.customer_id||0), endpoint:'/papi/payments',
    body:{ customer_name:(s.name||'Customer'), customer_phone:s.phone||null, order_no:s.order_no, invoice_no:s.invoice_no,
      kind:'settlement', currency:s.currency||'USD', amount:amt, method:PAY_METHOD, rate_to_usd:0,
      idempotency_key:id, created_date:now.toISOString() },
    label:('Receive '+(s.currency||'USD')+' '+finFmt(amt)) };
  var btn=document.querySelector('#paysheet .pay-go'); if(btn){ btn.disabled=true; btn.textContent='Recording…'; }
  if(navigator.onLine){
    var r=null, neterr=false;
    try{ r=await post(item.endpoint, item.body); }catch(e){ neterr=true; }
    if(!neterr && r && r.ok){ payClose(); toast('Payment recorded'); bosPayments(); return; }
    if(!neterr && r && r.ok===false){ toast('Could not record ('+esc(r.error||'error')+')', true); if(btn){ btn.disabled=false; btn.textContent='Record payment'; } return; }
    /* network hiccup mid-send → fall through and queue it */
  }
  await OB.add(item); if(typeof obBadge==='function') obBadge(); payClose(); toast('Saved offline — will sync'); bosPayments();
}
/* ===== Modern Settings screen (design v1.0) — replaces the settings-manager iframe on phones.
   Generic, schema-driven: renders /papi/settings-hub (sections → text/number/textarea/enum/toggle) and
   saves via /papi/settings-hub-save. Covers business profile, currency, and the fully-configurable VAT
   block (rate, on/off, inclusive, label, tax number). Owner-gated by the same endpoints. */
var SET_DATA=null;
async function bosSettings(){
  const v=document.getElementById('view'); v.innerHTML=skeleton();
  let r=null; try{ r=await getQuery('/papi/settings-hub',{}); }catch(e){}
  if(!r || r.ok===false){ v.innerHTML='<div class="mtoday"><div class="bcl-h"><div class="bcl-title">Settings</div></div>'+emptyState('bolt','Settings unavailable',(r&&r.message)||'Only an owner or manager can edit settings.')+'</div>'; return; }
  SET_DATA=r;
  const wa=r.whatsapp||{};
  let html='<div class="bcl-h"><div class="bcl-title">Settings</div></div>';
  html+='<div class="set-wa"><div class="set-wa-l"><div class="set-wa-t">WhatsApp</div><div class="set-wa-s">'+(wa.linked?('Connected'+(wa.number?(' · '+esc(wa.number)):'')):'Not connected')+'</div></div>'
    +'<button class="set-wa-b" onclick="route(\'wa-connect\')">'+(wa.linked?'Manage':'Connect')+'</button></div>';
  (r.sections||[]).forEach(function(sec){
    html+='<div class="c360-sec"><h3>'+esc(sec.label||sec.key)+'</h3></div>';
    (sec.groups||[]).forEach(function(g){
      html+='<div class="set-card">';
      if(g.label) html+='<div class="set-glabel">'+esc(g.label)+'</div>';
      if(g.help) html+='<div class="set-help">'+esc(g.help)+'</div>';
      (g.fields||[]).forEach(function(f){ html+=setField(f); });
      html+='</div>';
    });
  });
  html+='<button class="set-save" onclick="settingsSave(this)">Save changes</button>';
  v.innerHTML='<div class="mtoday">'+html+'</div>';
}
function setField(f){
  var k=esc(f.key||''), lbl=esc(f.label||f.key||''), val=f.value;
  var help=f.help?'<div class="set-fhelp">'+esc(f.help)+'</div>':'';
  if(f.type==='bool'){
    var on=(val===true||val==='1'||val===1);
    return '<label class="set-row set-toggle"><div class="set-l"><div class="set-lbl" style="margin:0">'+lbl+'</div>'+help+'</div>'
      +'<span class="set-sw'+(on?' on':'')+'" data-key="'+k+'" data-type="bool" onclick="setToggle(this)"><span class="set-knob"></span></span></label>';
  }
  if(f.type==='enum'){
    var opts=f.options||[], ol=f.optionLabels||{}, cur=(val!=null?String(val):(f.default!=null?String(f.default):''));
    var btns=opts.map(function(o){ return '<button class="set-seg'+(String(o)===cur?' on':'')+'" data-val="'+esc(o)+'" onclick="setSeg(this)">'+esc(ol[o]||o)+'</button>'; }).join('');
    return '<div class="set-row set-col"><div class="set-lbl">'+lbl+'</div>'+help+'<div class="set-segs" data-key="'+k+'" data-type="enum">'+btns+'</div></div>';
  }
  if(f.type==='textarea'){
    return '<div class="set-row set-col"><div class="set-lbl">'+lbl+'</div>'+help+'<textarea class="set-in set-ta" data-key="'+k+'" data-type="text">'+esc(val!=null?val:'')+'</textarea></div>';
  }
  if(f.type==='image'){
    /* v4.69.1 — a real Choose-photo field on the settings hub. Image-typed schema fields used to
       fall through to the bare text input below, so the screen asked for a URL nobody has. The
       button uploads through the existing owner-only /papi/branding-upload (which already accepts
       every image key in the schema — the v4.16 rule) and drops the returned URL into the hidden
       input that settingsSave() reads. Clear = back to the built-in fallback. */
    var iv=(val!=null?String(val):'');
    return '<div class="set-row set-col"><div class="set-lbl">'+lbl+'</div>'+help
      +'<div class="set-imgrow"><span class="set-imgprev" id="sfimg_'+k+'"'+(iv?' style="background-image:url(\''+esc(iv)+'\')"':'')+'></span>'
      +'<input type="hidden" data-key="'+k+'" data-type="text" id="sfimgv_'+k+'" value="'+esc(iv)+'">'
      +'<input type="file" accept="image/*" id="sfimgf_'+k+'" style="display:none" onchange="setImgUpload(\''+k+'\')">'
      +'<button type="button" class="set-imgbtn" onclick="document.getElementById(\'sfimgf_'+k+'\').click()">Choose photo</button>'
      +'<button type="button" class="set-imgbtn ghost" onclick="setImgClear(\''+k+'\')">Clear</button>'
      +'<span class="mut" id="sfimgs_'+k+'" style="font-size:12px"></span></div></div>';
  }
  var unit=f.unit?'<span class="set-unit">'+esc(f.unit)+'</span>':'';
  var typ=(f.type==='number')?'number':'text';
  return '<div class="set-row set-col"><div class="set-lbl">'+lbl+'</div>'+help
    +'<div class="set-inwrap"><input class="set-in" type="'+typ+'"'+(typ==='number'?' inputmode="decimal"':'')+' data-key="'+k+'" data-type="'+esc(f.type||'text')+'" value="'+esc(val!=null?val:(f.default!=null?f.default:''))+'">'+unit+'</div></div>';
}
function setToggle(el){ el.classList.toggle('on'); }
async function setImgUpload(k){
  var fi=document.getElementById('sfimgf_'+k); var f=fi&&fi.files&&fi.files[0]; if(!f) return;
  var st=document.getElementById('sfimgs_'+k); if(st) st.textContent='Uploading…';
  if(f.size>6*1024*1024){ if(st) st.textContent='Too large (max 6MB)'; return; }
  try{
    var fd=new FormData(); fd.append('key',k); fd.append('file',f);
    var res=await fetch('/papi/branding-upload',{method:'POST',credentials:'same-origin',headers:{'X-CSRF-TOKEN':CFG.csrf,'Accept':'application/json'},body:fd});
    // papi/* is CSRF-exempt, so a 419 cannot happen here — but the auth middleware still 401s on a
    // dead session, and the old code then threw on res.json() and showed a bare "Upload failed".
    if(res.status===401){ if(st) st.textContent='Session ended — sign in again'; sessionExpired(); return; }
    var d=await res.json();
    if(d&&d.ok&&d.url){ document.getElementById('sfimgv_'+k).value=d.url; var pv=document.getElementById('sfimg_'+k); if(pv) pv.style.backgroundImage='url(\''+d.url+'\')'; if(st) st.textContent='\u2713 uploaded \u2014 press Save'; }
    else { if(st) st.textContent=(d&&(d.error||d.message))||'Upload failed'; }
  }catch(e){ if(st) st.textContent='Upload failed'; }
}
function setImgClear(k){ var v=document.getElementById('sfimgv_'+k); if(v) v.value=''; var pv=document.getElementById('sfimg_'+k); if(pv) pv.style.backgroundImage=''; var st=document.getElementById('sfimgs_'+k); if(st) st.textContent='cleared \u2014 press Save'; }
function setSeg(btn){ var p=btn.parentNode; p.querySelectorAll('.set-seg').forEach(function(x){ x.classList.remove('on'); }); btn.classList.add('on'); }
async function settingsSave(btn){
  var vals={};
  document.querySelectorAll('#view [data-key]').forEach(function(el){
    var k=el.getAttribute('data-key'), t=el.getAttribute('data-type'); if(!k) return;
    if(t==='bool'){ vals[k]=el.classList.contains('on'); }
    else if(t==='enum'){ var on=el.querySelector('.set-seg.on'); vals[k]= on?on.getAttribute('data-val'):''; }
    else if(el.tagName==='TEXTAREA'||el.tagName==='INPUT'){ vals[k]=el.value; }
  });
  if(btn){ btn.disabled=true; btn.textContent='Saving…'; }
  var r=null, neterr=false; try{ r=await post('/papi/settings-hub-save',{values:vals}); }catch(e){ neterr=true; }
  if(btn){ btn.disabled=false; btn.textContent='Save changes'; }
  if(!neterr && r && r.ok){ toast(r.message||'Settings saved'); if(typeof bosSettings==='function') bosSettings(); }
  else { toast((r&&r.message)||'Could not save settings', true); }
}
/* ===== Modern Profit & Loss (design v1.0) — replaces the pnl-report iframe on phones. Cash-basis income
   statement from /papi/cash-pnl (reconciles to the cash book). Owner-gated by the endpoint. ===== */
async function bosPnl(){
  const v=document.getElementById('view'); v.innerHTML=skeleton();
  let r=null; try{ r=await getQuery('/papi/cash-pnl',{}); }catch(e){}
  if(!r || r.ok===false){ v.innerHTML='<div class="mtoday"><div class="bcl-h"><div class="bcl-title">Profit &amp; Loss</div></div>'+emptyState('chart','P&L unavailable',(r&&r.message)||'Only an owner or manager can view the P&L.')+'</div>'; return; }
  var is=r.income_statement||{}, eq=r.equity||{}, rec=r.reconciliation||{}, cur=r.currency||'USD';
  function money(n){ n=Number(n||0); return cur+' '+finFmt(n); }
  var op=Number(is.operating_profit||0);
  var hero='<div class="fin-hero"><div class="fin-hk">Operating profit · cash basis</div><div class="fin-hv" style="color:'+(op<0?'#FF6B5E':'#fff')+'">'+money(op)+'</div>'
    +'<div class="fin-hs">'+(is.gross_margin_pct!=null?('Gross margin '+is.gross_margin_pct+'%'):'since you started trading')+'</div></div>';
  function srow(label,val,opt){ opt=opt||{}; return '<div class="pnl-row'+(opt.strong?' strong':'')+(opt.tone?(' '+opt.tone):'')+'"><span class="pnl-l">'+esc(label)+'</span><span class="pnl-v">'+money(val)+'</span></div>'; }
  var rows='<div class="pnl-card">'
    + srow('Sales revenue', is.sales_revenue)
    + srow('Cost of goods', -Math.abs(Number(is.cost_of_goods||0)))
    + srow('Gross profit', is.gross_profit, {strong:true})
    + srow('Operating expenses', -Math.abs(Number(is.operating_expenses||0)))
    + (Number(is.other_cash_in_review||0)? srow('Other cash in (to review)', is.other_cash_in_review):'')
    + (Number(is.other_cash_out_review||0)? srow('Other cash out (to review)', -Math.abs(Number(is.other_cash_out_review||0))):'')
    + srow('Operating profit', is.operating_profit, {strong:true, tone:(op<0?'neg':'pos')})
    + '</div>';
  var extra='';
  if(Number(eq.capital_introduced||0)||Number(eq.drawings||0)){
    extra='<div class="c360-sec"><h3>Owner equity (not profit)</h3></div><div class="pnl-card">'
      + srow('Capital introduced', eq.capital_introduced)
      + srow('Drawings', -Math.abs(Number(eq.drawings||0)))
      + '</div>';
  }
  var note = (rec.reconciles===false)
    ? '<div class="pnl-note warn">Some cash rows aren’t classified yet — the “to review” lines show the gap.</div>'
    : '<div class="pnl-note">Cash basis · reconciles to your cash book.</div>';
  v.innerHTML='<div class="mtoday"><div class="bcl-h"><div class="bcl-title">Profit &amp; Loss</div></div>'+hero+'<div class="c360-sec"><h3>Income statement</h3></div>'+rows+extra+note+'</div>';
}
/* ===== Native in-app Task detail (design v1.0): view description + the uploaded photo + Mark-done
   (optional photo) + Comment. Reuses /papi/task/show, /complete, /comment. ===== */
var TASKD=null, TASKD_PHOTO=null;
async function bosTaskDetail(id){
  const v=document.getElementById('view'); v.innerHTML=skeleton();
  var r=null; try{ r=await getQuery('/papi/task/show',{id:id}); }catch(e){}
  if(!r || r.ok===false || !r.task){ v.innerHTML='<div class="mtoday"><div class="crumb"><span onclick="route(\'tasks\')" style="cursor:pointer;color:var(--brand-ink)">Tasks</span></div>'+emptyState('bolt','Task unavailable',(r&&r.error)||'Could not load this task.')+'</div>'; return; }
  TASKD=r.task; TASKD_PHOTO=null; var t=r.task;
  var badge = t.done ? '<span class="badge b-ok"><span class="d"></span>Done</span>'
    : (t.overdue ? '<span class="badge b-danger"><span class="d"></span>Overdue</span>'
    : (t.status==='in_progress' ? '<span class="badge b-info"><span class="d"></span>In progress</span>'
    : '<span class="badge b-warn"><span class="d"></span>'+esc(t.status_label)+'</span>'));
  var meta='<div class="tkd-meta">'
    +'<div class="tkd-kv"><span>Assignee</span><b>'+esc(t.assignee)+'</b></div>'
    +(t.due?('<div class="tkd-kv"><span>Due</span><b'+(t.overdue?' style="color:var(--danger)"':'')+'>'+esc(t.due)+'</b></div>'):'')
    +'<div class="tkd-kv"><span>Priority</span><b>'+esc(t.priority)+'</b></div>'
    +(t.done&&t.completed_by?('<div class="tkd-kv"><span>Completed by</span><b>'+esc(t.completed_by)+(t.completed_at?(' · '+esc(t.completed_at)):'')+'</b></div>'):'')
    +'</div>';
  var desc = t.note ? '<div class="tkd-desc">'+esc(t.note)+'</div>' : '';
  var photo='';
  if(t.attachment){
    var isImg=/\.(jpg|jpeg|png|webp)$/i.test(t.attachment);
    photo='<div class="c360-sec"><h3>Photo</h3></div>'+(isImg?'<img class="tkd-photo" src="'+esc(t.attachment)+'" alt="Task photo" onclick="window.open(this.src,\'_blank\')">':'<a class="tkd-file" href="'+esc(t.attachment)+'" target="_blank">📎 View attached file</a>');
  }
  var actions='';
  if(t.active){
    actions='<div class="c360-sec"><h3>Complete</h3></div><div class="tkd-do">'
      +'<label class="pick"><span id="tkdPickText">📷 Add a photo (optional)</span><input type="file" id="tkdPhoto" accept="image/*,application/pdf" onchange="tkdPick()" style="display:none"></label>'
      +'<img id="tkdPreview" class="tkd-photo" style="display:none" alt="preview">'
      +'<textarea id="tkdNote" class="set-in set-ta" placeholder="Add a note (optional)…"></textarea>'
      +'<button class="pay-go" onclick="tkdDone(this)">✓ Mark as done</button></div>';
  } else if(t.done){ actions='<div class="tkd-complete">✅ This task is complete.</div>'; }
  var cbox = t.active ? '<div class="c360-sec"><h3>Comment</h3></div><div class="tkd-cbox"><input id="tkdComment" class="set-in" placeholder="Write a comment…"><button class="set-cbtn" onclick="tkdComment(this)">Send</button></div>' : '';
  var ev=(r.events||[]);
  var tl = ev.length ? '<div class="c360-sec"><h3>Activity</h3></div><div class="tkd-tl">'+ev.map(function(e){ return '<div class="tkd-ev"><div class="tkd-ev-h"><b>'+esc(e.event)+'</b>'+(e.actor?(' · '+esc(e.actor)):'')+'<span>'+esc(e.when||'')+'</span></div>'+(e.detail?'<div class="tkd-ev-d">'+esc(e.detail)+'</div>':'')+'</div>'; }).join('')+'</div>' : '';
  v.innerHTML='<div class="mtoday"><div class="crumb"><span onclick="route(\'tasks\')" style="cursor:pointer;color:var(--brand-ink)">‹ Tasks</span></div>'
    +'<div class="tkd-head"><div class="tkd-title">'+(t.ref?('<span style="color:var(--muted);font-weight:600;margin-right:6px">'+esc(t.ref)+'</span>'):'')+esc(t.title)+'</div>'+badge+'</div>'
    +meta+desc+photo+actions+cbox+tl+'</div>';
}
function tkdPick(){
  var inp=document.getElementById('tkdPhoto'); var f=inp.files&&inp.files[0]; if(!f) return;
  if(f.size>15*1024*1024){ toast('Photo too big (max 15 MB)', true); inp.value=''; return; }
  var rd=new FileReader(); rd.onload=function(){ TASKD_PHOTO={data:rd.result,name:f.name||'photo.jpg'};
    document.getElementById('tkdPickText').textContent='📷 '+(f.name||'Photo selected');
    var pv=document.getElementById('tkdPreview'); if(/^image\//.test(f.type)){ pv.src=rd.result; pv.style.display='block'; } };
  rd.readAsDataURL(f);
}
async function tkdDone(btn){
  if(!TASKD) return; btn.disabled=true; btn.textContent='Saving…';
  var body={ id:TASKD.id, note:((document.getElementById('tkdNote')||{}).value||'') };
  if(TASKD_PHOTO){ body.attachment_data=TASKD_PHOTO.data; body.attachment_name=TASKD_PHOTO.name; }
  var r=null; try{ r=await post('/papi/task/complete', body); }catch(e){}
  if(r && r.ok){ toast('Task completed'); bosTaskDetail(TASKD.id); }
  else { btn.disabled=false; btn.textContent='✓ Mark as done'; toast((r&&(r.message||r.error))||'Could not complete', true); }
}
async function tkdComment(btn){
  if(!TASKD) return; var el=document.getElementById('tkdComment'); var text=((el&&el.value)||'').trim();
  if(!text){ toast('Write a comment', true); return; }
  btn.disabled=true;
  var r=null; try{ r=await post('/papi/task/comment',{id:TASKD.id, text:text}); }catch(e){}
  btn.disabled=false;
  if(r && r.ok){ toast('Comment added'); bosTaskDetail(TASKD.id); }
  else { toast((r&&(r.message||r.error))||'Could not comment', true); }
}
/* ===== Quick Invoice (design v1.0) — issue a numbered invoice from an existing order. Reuses the
   EXISTING, idempotent /papi/invoice-issue (derives customer + lines from the order; never double-numbers)
   and /papi/invoice-send. No new money-write path. ===== */
var QINV=[], QINV_SEL=null, QINV_RESULT=null;
async function bosQuickInvoice(){
  const v=document.getElementById('view'); v.innerHTML=skeleton();
  let r=null; try{ r=await getQuery('/papi/orders',{}); }catch(e){}
  QINV=((r&&r.orders)||[]).filter(function(o){ return Number(o.total_ugx||0)>0; }).slice(0,40);
  var head='<div class="bcl-h"><div class="bcl-title">Issue invoice</div></div>'
    +'<div class="qinv-hint">Pick an order to turn into a numbered invoice. Already-invoiced orders just return their existing one — no double numbering.</div>';
  var list = QINV.length ? '<div class="fin-list">'+QINV.map(qinvRow).join('')+'</div>' : '<div class="btl-empty">No orders to invoice right now.</div>';
  v.innerHTML='<div class="mtoday">'+head+list+'</div>';
}
function qinvRow(o,i){
  var cur=o.sell_currency||'USD';
  return '<div class="fin-c" onclick="qinvOpen('+i+')"><div class="fin-ic" style="background:#EDEBFB;color:#6C5CE7"><svg class="i" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg></div>'
    +'<div class="fin-bd"><div class="fin-t">'+esc(o.name||'Customer')+'</div><div class="fin-s">'+esc(o.order_no||'')+(o.status?(' · '+esc(o.status)):'')+'</div></div>'
    +'<div class="fin-r"><b>'+esc(cur)+' '+finFmt(o.total_ugx)+'</b><svg class="i fin-chev" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></div></div>';
}
function qinvOpen(i){
  QINV_SEL=i; QINV_RESULT=null; var o=QINV[i]; if(!o) return; var cur=o.sell_currency||'USD';
  var s=fsheetEnsure();
  s.innerHTML='<div class="qsheet paysheet" role="dialog"><div class="grab"></div>'
    +'<div class="pay-h">Issue invoice</div><div class="pay-sub">'+esc(o.name||'Customer')+' · '+esc(o.order_no||'')+'</div>'
    +'<div class="qinv-amt">'+esc(cur)+' '+finFmt(o.total_ugx)+'</div>'
    +'<div class="pay-sub" style="padding-top:0">A permanent numbered invoice is created from this order’s items. Safe to tap again — it won’t double-number.</div>'
    +'<button class="pay-go" onclick="qinvIssue(this)">Issue invoice</button><button class="pay-cancel" onclick="fsheetClose()">Cancel</button></div>';
  s.classList.add('on');
}
async function qinvIssue(btn){
  if(QINV_SEL==null) return; var o=QINV[QINV_SEL]; if(!o) return;
  if(btn){ btn.disabled=true; btn.textContent='Issuing…'; }
  var r=null,neterr=false; try{ r=await post('/papi/invoice-issue',{id:o.id}); }catch(e){ neterr=true; }
  if(!neterr && r && r.ok){
    QINV_RESULT=r; var s=document.getElementById('formsheet');
    if(s) s.innerHTML='<div class="qsheet paysheet" role="dialog"><div class="grab"></div>'
      +'<div class="qinv-done"><div class="qinv-check"><svg class="i" viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg></div>'
      +'<div class="pay-h" style="text-align:center">Invoice '+esc(r.invoice_no||'')+'</div>'
      +'<div class="pay-sub" style="text-align:center">Issued for '+esc(o.name||'')+'</div></div>'
      +'<button class="pay-go" onclick="qinvSend(this)">Send on WhatsApp</button>'
      +'<button class="pay-cancel" onclick="fsheetClose();bosQuickInvoice()">Done</button></div>';
    toast('Invoice '+(r.invoice_no||'')+' issued');
  } else {
    if(btn){ btn.disabled=false; btn.textContent='Issue invoice'; }
    var msg=(r&&(r.error==='nothing_to_invoice'?'This order has no items to invoice':(r.error==='pdf_unavailable'?'Invoice PDF isn’t available on the server':(r.message||r.error))))||'Could not issue the invoice';
    toast(msg, true);
  }
}
async function qinvSend(btn){
  if(!QINV_RESULT) return; var o=QINV[QINV_SEL]||{};
  if(btn){ btn.disabled=true; btn.textContent='Sending…'; }
  var r=null,neterr=false; try{ r=await post('/papi/invoice-send',{id:QINV_RESULT.id, phone:o.phone||''}); }catch(e){ neterr=true; }
  if(btn){ btn.disabled=false; btn.textContent='Send on WhatsApp'; }
  if(!neterr && r && r.ok){ toast('Invoice sent on WhatsApp'); fsheetClose(); bosQuickInvoice(); }
  else { toast((r&&(r.message||r.error))||'Issued — send it from the invoice screen if needed', true); }
}
/* Modern Customers list (design v1.0): search + owe/overdue filters + tap-to-360. */
var BCL=[], BCL_F='all';
async function bosCustomers(){
  const v=document.getElementById('view'); v.innerHTML=skeleton();
  let r; try{ r=await getQuery('/papi/bos-customers',{}); }catch(e){ r=null; }
  if(!r || r.ok===false){ v.innerHTML=emptyState('user','Customers unavailable','The list could not load right now.'); return; }
  BCL=r.customers||[]; BCL_F='all'; const cn=r.counts||{};
  const head='<div class="bcl-h"><div class="bcl-title">Customers</div></div>'
    +'<div class="bcl-srch"><svg class="i" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg><input id="bclq" placeholder="Search customers" oninput="bosCustFilter()"></div>'
    +'<div class="fchips"><button class="fchip on" onclick="bosCustF(this,\'all\')">All · '+(cn.all||0)+'</button>'
    +'<button class="fchip" onclick="bosCustF(this,\'owes\')">Owes · '+(cn.owes||0)+'</button>'
    +'<button class="fchip" onclick="bosCustF(this,\'overdue\')">Overdue · '+(cn.overdue||0)+'</button></div>';
  v.innerHTML='<div class="mtoday">'+head+'<div id="bcllist">'+bosCustRows(BCL)+'</div></div>';
}
function bosCustRow(c){
  const out=Number(c.outstanding||0), st=c.status||'ok';
  const dot=st==='overdue'?'var(--red)':(st==='owes'?'var(--amber)':'var(--brand)');
  const right= out>0.5
    ? '<b>USD '+bmoney(out)+'</b><div class="bcl-lab '+(st==='overdue'?'red':'amber')+'">'+(st==='overdue'?'Overdue':'Owes')+'</div>'
    : '<b class="bcl-set">USD 0</b><div class="bcl-lab ok">Settled</div>';
  const sub=(c.city?esc(c.city):'')+(c.last_order?((c.city?' · ':'')+'last order '+esc(c.last_order)):'');
  return '<div class="bcl-c" onclick="route(\'bos.timeline\','+Number(c.id)+')">'
    +'<div class="bcl-av" style="background:'+avColor(c.name||'?')+'">'+bInit(c.name)+'</div>'
    +'<div class="bcl-bd"><div class="bcl-nm">'+(out>0.5?'<span class="bcl-dot" style="background:'+dot+'"></span>':'')+esc(c.name||'Customer')+'</div>'
    +'<div class="bcl-s">'+sub+'</div></div>'
    +'<div class="bcl-r">'+right+'</div></div>';
}
function bosCustRows(list){ return list.length ? list.map(bosCustRow).join('') : emptyState('user','No customers','No one matches this filter yet.'); }
function bosCustF(btn,f){ BCL_F=f; btn.parentNode.querySelectorAll('.fchip').forEach(function(x){x.classList.remove('on')}); btn.classList.add('on'); bosCustFilter(); }
function bosCustFilter(){
  const q=(((document.getElementById('bclq')||{}).value)||'').trim().toLowerCase();
  const rows=BCL.filter(function(c){
    if(BCL_F==='owes' && !(Number(c.outstanding)>0.5)) return false;
    if(BCL_F==='overdue' && c.status!=='overdue') return false;
    if(q){ const hay=((c.name||'')+' '+(c.city||'')+' '+(c.phone||'')).toLowerCase(); if(hay.indexOf(q)<0) return false; }
    return true;
  });
  const box=document.getElementById('bcllist'); if(box) box.innerHTML=bosCustRows(rows);
}
/* Modern Customer-360 (design system v1.0): avatar + score header, real stats strip, O2C rail, filtered history. */
function bmoneyK(v){ v=Number(v||0); if(v>=1000){ var k=v/1000; return 'USD '+(k>=100?Math.round(k):k.toFixed(k>=10?0:1))+'k'; } return 'USD '+Math.round(v); }
function bInit(n){ n=(n||'').trim(); if(!n) return '?'; var p=n.split(/\s+/); return ((p[0][0]||'?')+(p[1]?p[1][0]:'')).toUpperCase(); }
function bStars(s){ s=Math.max(0,Math.min(5,Math.round(Number(s||0)))); return '★★★★★'.slice(0,s)+'☆☆☆☆☆'.slice(0,5-s); }
async function bosTimeline(id){
  const v=document.getElementById('view'); v.innerHTML=skeleton();
  let r; try{ r=await post(CFG.base+'/customer/'+id+'/timeline', {id:id}); }catch(e){ r=null; }
  if(!r || r.ok===false){ v.innerHTML=emptyState('bolt','Timeline unavailable','No history yet, or the event spine isn\'t enabled.'); return; }
  const c=r.customer||{}, t=r.timeline||[]; const out=Number(c.outstanding||0);
  OB_CTX={ customer_id:Number(c.id||id), name:(c.name||''), phone:(c.phone||''),
    collect:(r.journey&&r.journey.next&&r.journey.next.collect)||null };
  const cr=c.credit||{}, stand=cr.standing||'good';
  const standLabel={good:'Good',watch:'Watch',over:'Over'}[stand]||'Good';
  const scoreWord=Number(c.score)>=4?'Top customer':(Number(c.score)<=2?'Needs attention':'Regular');
  const header='<div class="c360-hd"><div class="c360-av" style="background:'+avColor(c.name||'?')+'">'+bInit(c.name)+'</div>'
    +'<div class="c360-id"><div class="c360-nm">'+esc(c.name||'Customer')+'</div>'
    +'<div class="c360-sub">'+esc((c.city?c.city+' · ':'')+(c.phone||''))+'</div>'
    +'<div class="c360-stars">'+bStars(c.score)+' <span>'+scoreWord+'</span></div></div></div>';
  const strip='<div class="c360-strip">'
    +'<div class="c360-c"><div class="c360-cv '+(out>0.5?'amber':'ok')+'">'+(out>0.5?bmoneyK(out):'—')+'</div><div class="c360-cl">Outstanding</div></div>'
    +'<div class="c360-c"><div class="c360-cv">'+bmoneyK(c.lifetime)+'</div><div class="c360-cl">Lifetime</div></div>'
    +'<div class="c360-c"><div class="c360-cv">'+esc(c.last_order||'—')+'</div><div class="c360-cl">Last order</div></div>'
    +'<div class="c360-c"><div class="c360-cv '+(stand==='good'?'ok':(stand==='over'?'red':'amber'))+'">'+standLabel+'</div><div class="c360-cl">Credit</div></div>'
    +'</div>';
  const chips='<div class="c360-sec"><h3>History</h3></div>'
    +'<div class="fchips"><button class="fchip on" onclick="bosTlf(this,\'all\')">All</button>'
    +'<button class="fchip" onclick="bosTlf(this,\'pay\')">Payments</button>'
    +'<button class="fchip" onclick="bosTlf(this,\'inv\')">Invoices</button>'
    +'<button class="fchip" onclick="bosTlf(this,\'visit\')">Visits</button></div>';
  const rows = t.length ? '<div class="c360-tl" id="c360tl">'+t.map(bosTLine).join('')+'</div>'
    : '<div class="btl-empty">No activity yet — actions will appear here as they happen.</div>';
  v.innerHTML='<div class="c360-wrap"><div class="crumb"><span onclick="route(\'customers\')" style="cursor:pointer;color:var(--brand-ink)">Customers</span> <span style="color:var(--faint)">/</span> '+esc(c.name||'')
    +' <span class="offpill" style="display:'+(navigator.onLine?'none':'inline-block')+'">Offline</span> <span class="obchip" style="display:none"></span></div>'
    +header+strip+bosJourney(r.journey)+obComposer(Number(c.id||id))+'<div id="obpend"></div>'+chips+rows+'</div>';
  renderObPending(); obBadge();
}
function bosTlf(btn,tp){ btn.parentNode.querySelectorAll('.fchip').forEach(function(x){x.classList.remove('on')}); btn.classList.add('on');
  document.querySelectorAll('#c360tl .c360-e').forEach(function(e){ e.style.display=(tp==='all'||e.dataset.t===tp)?'':'none'; }); }
/* BOS Slice 4 — Order-to-Cash rail + the single next step (read-only; deep-links to the verb's screen). */
function bosJourney(j){
  if(!j || !j.steps || !j.steps.length) return '';
  const steps=j.steps.map(function(s){
    return '<div class="bjr-step '+s.state+'"><div class="bjr-dot">'+(s.state==='done'?'✓':'')+'</div><div class="bjr-lb">'+esc(s.label)+'</div></div>';
  }).join('<div class="bjr-line"></div>');
  const n=j.next; let nb;
  if(n && n.collect){
    nb=obCollectCard(n);                        // Slice 6 — collect inline (works offline), not a deep-link
  } else if(n){
    const rt=(n.route&&n.route.key)?(' onclick="route(\''+n.route.key+'\','+Number(n.route.id||0)+')"'):'';
    nb='<div class="bjr-next '+(n.tone||'default')+'"'+rt+'><div class="bjr-nx"><div class="bjr-nl">Next: '+esc(n.label)+'</div>'+(n.hint?'<div class="bjr-nh">'+esc(n.hint)+'</div>':'')+'</div><div class="bjr-ng">›</div></div>';
  } else {
    nb='<div class="bjr-next ok"><div class="bjr-nx"><div class="bjr-nl">All caught up on this customer</div></div><div class="bjr-ng">✓</div></div>';
  }
  return '<div class="bjr-wrap"><div class="bjr">'+steps+'</div>'+nb+'</div>';
}
function bmoney(v){ return Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}); }
function bwhen(iso){ if(!iso) return ''; try{ const d=new Date(iso); return d.toLocaleDateString(undefined,{day:'2-digit',month:'short'})+' '+d.toLocaleTimeString(undefined,{hour:'2-digit',minute:'2-digit'}); }catch(e){ return ''; } }
function bosTLine(e){
  const d=e.data||{}, cur=d.currency||'USD', amt=(x)=>cur+' '+bmoney(x);
  const IC={
    card:'<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2"/>',
    doc:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
    pin:'<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
    pencil:'<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4z"/>',
    user:'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    bell:'<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
    box:'<path d="M21 8 12 3 3 8v8l9 5 9-5V8Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>'
  };
  // event -> [iconKey, category, title, bigAmount]
  const M={
    CustomerCreated:['user','visit','New customer'+(d.segment?' — '+esc(d.segment):''),''],
    CustomerVisited:['pin','visit', d.note?('Visit — '+esc(d.note)):('Visited'+(d.city?' — '+esc(d.city):'')),''],
    CustomerNote:['pencil','visit','Note — '+esc(d.note||''),''],
    QuoteCreated:['doc','inv','Quote '+esc(d.quote_no||''), d.total?amt(d.total):''],
    QuoteAccepted:['doc','inv','Quote accepted',''], QuoteConverted:['doc','inv','Quote → order',''],
    OrderConfirmed:['box','inv','Order '+esc(d.order_no||'')+' confirmed',''], DeliveryCompleted:['box','inv','Delivered',''],
    InvoiceIssued:['doc','inv','Invoice '+esc(d.invoice_no||''), amt(d.total)+(Number(d.balance)>0.5?' · bal '+bmoney(d.balance):'')],
    PaymentReceived:['card','pay','Payment received'+(d.receipt_no?' · '+esc(d.receipt_no):''), amt(d.amount)],
    ReceiptSent:['card','pay','Receipt sent',''],
    FollowupScheduled:['bell','visit', d.note?('Follow-up — '+esc(d.note)+(d.due?' (by '+esc(d.due)+')':'')):'Follow-up scheduled','']
  };
  const m=M[e.event]||['doc','all',esc(e.event),''];
  const big=m[3]?'<div class="c360-big '+m[1]+'">'+m[3]+'</div>':'';
  return '<div class="c360-e" data-t="'+m[1]+'"><div class="c360-ci '+m[1]+'"><svg class="i" viewBox="0 0 24 24">'+IC[m[0]]+'</svg></div>'
    +'<div class="c360-bd"><div class="c360-tt">'+m[2]+'</div>'+big+'<div class="c360-aa">'+esc(bwhen(e.at))+(e.actor?' · '+esc(e.actor):'')+'</div></div></div>';
}

/* ===== BOS Slice 5 — Offline outbox: field writes survive no signal, then sync idempotently =====
   A tiny IndexedDB queue (with an in-memory fallback). A note posts immediately when there's signal;
   with no signal it's saved locally + shown as "waiting to sync", and a background flush drains the
   queue on reconnect. Every item carries a client idempotency key, so a replay is a no-op server-side
   (EventStore dedupes) — nothing is ever double-counted. */
const OB = (function(){
  const DBN='cloudbss-ob', STORE='outbox'; let _db=null, _mem=[];
  function open(){ return new Promise(function(res){
    if(_db) return res(_db);
    try{ const rq=indexedDB.open(DBN,1);
      rq.onupgradeneeded=function(){ try{ rq.result.createObjectStore(STORE,{keyPath:'id'}); }catch(e){} };
      rq.onsuccess=function(){ _db=rq.result; res(_db); };
      rq.onerror=function(){ res(null); };
    }catch(e){ res(null); }
  }); }
  function store(mode){ return open().then(function(db){ try{ return db? db.transaction(STORE,mode).objectStore(STORE):null; }catch(e){ return null; } }); }
  function uid(){ try{ if(crypto&&crypto.randomUUID) return crypto.randomUUID(); }catch(e){} return 'ob-'+Date.now()+'-'+Math.random().toString(16).slice(2); }
  return {
    uuid:uid,
    add:function(item){ return store('readwrite').then(function(st){
      if(!st){ _mem.push(item); return item; }
      return new Promise(function(res){ try{ st.add(item); st.transaction.oncomplete=function(){res(item);}; st.transaction.onerror=function(){ _mem.push(item); res(item); }; }catch(e){ _mem.push(item); res(item); } });
    }); },
    all:function(){ return store('readonly').then(function(st){
      if(!st) return _mem.slice();
      return new Promise(function(res){ try{ const rq=st.getAll(); rq.onsuccess=function(){ res(rq.result||[]); }; rq.onerror=function(){ res(_mem.slice()); }; }catch(e){ res(_mem.slice()); } });
    }); },
    del:function(id){ return store('readwrite').then(function(st){
      if(!st){ _mem=_mem.filter(function(x){return x.id!==id;}); return; }
      return new Promise(function(res){ try{ st.delete(id); st.transaction.oncomplete=function(){res();}; st.transaction.onerror=function(){ _mem=_mem.filter(function(x){return x.id!==id;}); res(); }; }catch(e){ res(); } });
    }); },
    put:function(item){ return store('readwrite').then(function(st){   // update in place (keyPath id)
      if(!st){ let f=false; _mem=_mem.map(function(x){ if(x.id===item.id){f=true;return item;} return x; }); if(!f) _mem.push(item); return item; }
      return new Promise(function(res){ try{ st.put(item); st.transaction.oncomplete=function(){res(item);}; st.transaction.onerror=function(){ res(item); }; }catch(e){ res(item); } });
    }); }
  };
})();
let OB_CUST=0, OB_KIND='visit', OB_BUSY=false, OB_CTX=null, OB_METHOD='Cash';
function obKind(k){ OB_KIND=k; document.querySelectorAll('.obk').forEach(function(b){ b.classList.toggle('on', b.dataset.k===k); }); }
/* Slice 6 — collect against the open invoice, inline and offline-safe (idempotent server-side). */
function obCollectCard(n){
  const cl=n.collect||{}, cur=cl.currency||'USD', amt=Number(cl.amount||0);
  const mchip=function(m){ return '<button class="obk'+(m===OB_METHOD?' on':'')+'" data-m="'+m+'" onclick="obMethod(\''+m+'\')">'+m+'</button>'; };
  return '<div class="obcol"><div class="obcol-h"><div class="obcol-t">Collect '+esc(cur)+' '+bmoney(amt)+'</div>'
    +'<div class="obcol-s">'+esc(cl.invoice_no||'')+' · balance due · works offline</div></div>'
    +'<div class="obcol-r"><span class="obcol-cur">'+esc(cur)+'</span>'
    +'<input id="obamt" class="obc-in" inputmode="decimal" value="'+amt.toFixed(2)+'" oninput="obDiscHint()"></div>'
    +'<div class="obc-k" style="margin:9px 0 0">'+mchip('Cash')+mchip('MoMo')+mchip('Bank')+'</div>'
    +'<label class="obdisc" id="obdiscwrap" style="display:none"><input type="checkbox" id="obdisc"><span id="obdisch"></span></label>'
    +'<button class="obcol-go" onclick="obCollect()">Record payment</button></div>';
}
function obMethod(m){ OB_METHOD=m; document.querySelectorAll('.obcol [data-m]').forEach(function(b){ b.classList.toggle('on', b.dataset.m===m); }); }
/* When you collect LESS than the balance, offer to write off the rest as a discount (closes the invoice). */
function obDiscHint(){
  var wrap=document.getElementById('obdiscwrap'), cb=document.getElementById('obdisc'), h=document.getElementById('obdisch');
  if(!wrap||!cb||!h||!OB_CTX||!OB_CTX.collect) return;
  var bal=Number(OB_CTX.collect.amount||0), cur=OB_CTX.collect.currency||'USD';
  var amt=Math.round((parseFloat((document.getElementById('obamt')||{}).value)||0)*100)/100;
  var d=Math.round((bal-amt)*100)/100;
  if(d>0.005 && amt>0){ h.textContent=' Write off '+cur+' '+bmoney(d)+' as a discount — close the invoice'; wrap.style.display=''; }
  else { cb.checked=false; wrap.style.display='none'; }
}
async function obCollect(){
  if(!OB_CTX || !OB_CTX.collect){ return; }
  const el=document.getElementById('obamt'); const amt=Math.round((parseFloat(el&&el.value)||0)*100)/100;
  if(!(amt>0)){ toast('Enter an amount', true); return; }
  const cl=OB_CTX.collect, bal=Number(cl.amount||0);
  const dcb=document.getElementById('obdisc');
  const discount=(dcb&&dcb.checked&&(bal-amt)>0.005)?Math.round((bal-amt)*100)/100:0;
  const id=OB.uuid(), now=new Date();
  const item={ id:id, ts:now.getTime(), kind:'collect', customer_id:OB_CTX.customer_id, endpoint:'/papi/payments',
    body:{ customer_name:(OB_CTX.name||OB_CTX.phone||'Customer'), customer_phone:OB_CTX.phone, order_no:cl.order_no,
      invoice_no:cl.invoice_no, kind:'settlement', currency:cl.currency, amount:amt, method:OB_METHOD,
      rate_to_usd:0, discount:discount, idempotency_key:id, created_date:now.toISOString() },
    label:('Collect '+cl.currency+' '+bmoney(amt)+(discount>0?(' · '+bmoney(discount)+' disc'):'')) };
  if(navigator.onLine){
    let r=null, neterr=false;
    try{ r=await post(item.endpoint, item.body); }catch(e){ neterr=true; }
    if(!neterr && r && r.ok){ toast(r.discount_applied?'Recorded · invoice closed with discount':'Payment recorded'); bosTimeline(OB_CTX.customer_id); return; }
    if(!neterr && r && r.ok===false){ toast('Could not record ('+esc(r.error||'error')+')', true); return; }
    /* network failed mid-send → fall through and queue it */
  }
  await OB.add(item); await renderObPending(); await obBadge();
  toast('Saved offline — will sync');
}
function obComposer(cid){
  OB_CUST=Number(cid)||0;
  const chip=function(k,l){ return '<button class="obk'+(k===OB_KIND?' on':'')+'" data-k="'+k+'" onclick="obKind(\''+k+'\')">'+l+'</button>'; };
  return '<div class="obc"><div class="obc-k">'+chip('visit','📍 Visit')+chip('note','📝 Note')+chip('followup','🔔 Follow-up')+'</div>'
    +'<div class="obc-r"><input id="obtext" class="obc-in" maxlength="1000" placeholder="What happened? (works offline)" onkeydown="if(event.key===\'Enter\')obSend()"><button class="obc-go" onclick="obSend()">Send</button></div></div>';
}
function obPendingLine(it){
  const k=it.kind;
  let txt, ic='⏳';
  if(k==='collect'){ ic='💵'; txt=esc(it.label||('Collect '+((it.body&&it.body.currency)||'')+' '+bmoney((it.body&&it.body.amount)||0))); }
  else { const lbl=(k==='visit'?'📍 Visit':(k==='followup'?'🔔 Follow-up':'📝 Note')); txt=lbl+' — '+esc((it.body&&it.body.text)||''); }
  if(it.status==='failed'){
    return '<div class="btl-row obp"><div class="btl-ic">⚠️</div><div class="btl-body"><div class="btl-txt">'+txt+'</div>'
      +'<div class="btl-at" style="color:#E24B3B;font-weight:750">Not sent — '+esc(it.error||'rejected')+'</div>'
      +'<div style="margin-top:7px;display:flex;gap:8px">'
      +'<button onclick="obRetry(\''+it.id+'\')" style="font:inherit;font-size:12px;font-weight:750;border:0;border-radius:8px;padding:6px 14px;background:#12A150;color:#fff;cursor:pointer">Retry</button>'
      +'<button onclick="obDiscard(\''+it.id+'\')" style="font:inherit;font-size:12px;font-weight:750;border:0;border-radius:8px;padding:6px 14px;background:#EEF1F4;color:#42505F;cursor:pointer">Discard</button>'
      +'</div></div></div>';
  }
  return '<div class="btl-row obp"><div class="btl-ic">'+ic+'</div><div class="btl-body"><div class="btl-txt">'+txt+'</div><div class="btl-at">waiting to sync</div></div></div>';
}
async function renderObPending(){
  const box=document.getElementById('obpend'); if(!box) return;
  let items=[]; try{ items=await OB.all(); }catch(e){ items=[]; }
  items=items.filter(function(x){ return Number(x.customer_id)===Number(OB_CUST); }).sort(function(a,b){ return (b.ts||0)-(a.ts||0); });
  box.innerHTML=items.map(obPendingLine).join('');
}
async function obBadge(){
  let items=[]; try{ items=await OB.all(); }catch(e){ items=[]; }
  const failed=items.filter(function(x){return x.status==='failed';}).length, pend=items.length-failed;
  document.querySelectorAll('.obchip').forEach(function(c){
    if(failed>0){ c.textContent='⚠ '+failed+' need attention'+(pend>0?(' · ⏳ '+pend+' to sync'):''); c.style.display=''; }
    else if(pend>0){ c.textContent='⏳ '+pend+' to sync'; c.style.display=''; }
    else { c.style.display='none'; }
  });
}
/* Manual retry of a rejected item: clear the failed flag and try to flush now. Field data is never lost silently. */
async function obRetry(id){
  try{ const items=await OB.all(); const it=items.filter(function(x){return x.id===id;})[0];
    if(it){ it.status='pending'; it.error=null; await OB.put(it); } }catch(e){}
  await renderObPending(); await obBadge();
  if(navigator.onLine){ obFlush(); } else { toast('Will retry when back online'); }
}
/* Explicit user discard — the ONLY way a queued item leaves the outbox without succeeding. */
async function obDiscard(id){
  if(!confirm('Discard this unsent item? It has not reached the server and cannot be recovered.')) return;
  try{ await OB.del(id); }catch(e){}
  await renderObPending(); await obBadge();
  toast('Discarded');
}
async function obSend(){
  const el=document.getElementById('obtext'); if(!el) return;
  const text=(el.value||'').trim(); if(!text) return;
  const id=OB.uuid(), now=new Date();
  const item={ id:id, ts:now.getTime(), kind:OB_KIND, customer_id:OB_CUST, endpoint:'/papi/customer-note',
    body:{ customer_id:OB_CUST, kind:OB_KIND, text:text, idempotency_key:id, occurred_at:now.toISOString(), connectivity:(navigator.onLine?'online':'offline') } };
  el.value='';
  if(navigator.onLine){
    let r=null, neterr=false;
    try{ r=await post(item.endpoint, item.body); }catch(e){ neterr=true; }
    if(!neterr && r && r.ok){ toast('Saved'); bosTimeline(OB_CUST); return; }
    if(!neterr && r && r.ok===false){ toast(r.error==='empty'?'Add a note first':'Could not save', true); el.value=text; return; }
    /* network failed mid-send → fall through and queue it */
  }
  await OB.add(item); await renderObPending(); await obBadge();
  toast('Saved offline — will sync');
}
async function obFlush(){
  if(OB_BUSY || !navigator.onLine) return; OB_BUSY=true; let changed=false;
  try{
    let items=[]; try{ items=await OB.all(); }catch(e){ items=[]; }
    items.sort(function(a,b){ return (a.ts||0)-(b.ts||0); });   // FIFO — preserve field order
    for(let i=0;i<items.length;i++){ const it=items[i];
      if(it.status==='failed') continue;                         // rejected before — waits for a manual retry, don't re-hammer
      let r=null, neterr=false;
      try{ r=await post(it.endpoint, it.body); }catch(e){ neterr=true; }
      if(neterr) break;                                          // still unreachable (network or non-JSON 5xx) — keep the rest, retry later
      if(r && r.ok){ await OB.del(it.id); changed=true; }
      else {
        // Server returned a definite rejection. NEVER delete field data — keep it, flag it, surface it for manual retry/discard.
        it.status='failed'; it.attempts=(it.attempts||0)+1; it.error=(r&&r.error)?String(r.error):'rejected'; it.failedAt=Date.now();
        await OB.put(it); changed=true;
      }
    }
  } finally { OB_BUSY=false; }
  await obBadge();
  if(changed && CUR && CUR.key==='bos.timeline'){ bosTimeline(CUR.id); }   // pending → real
}
function obConn(){ const off=!navigator.onLine; document.querySelectorAll('.offpill').forEach(function(p){ p.style.display=off?'inline-block':'none'; }); try{ mtSync(); }catch(e){} }
(function(){ try{
  window.addEventListener('online', function(){ obConn(); obFlush(); });
  window.addEventListener('offline', obConn);
  setInterval(function(){ obFlush(); }, 30000);
  obFlush();
}catch(e){} })();

/* ===== BOS Slice 3 — Today: the day's work (greeting + KPI tiles + ranked to-do) =====
   Pure read of the DailyToday projection: every figure traces to a live object query (no invented
   numbers). Collect activities deep-link into the customer timeline (Slice 2) — one tap from work to
   context. Mobile-first; desktop reflows the tiles to a 4-up row. */
async function bosToday(){
  const v=document.getElementById('view'); v.innerHTML=skeleton();
  let r; try{ r=await getQuery('/papi/bos-today',{}); }catch(e){ r=null; }
  if(!r || r.ok===false){ v.innerHTML=emptyState('bolt','Today unavailable','The day view could not load right now.'); return; }
  const nm=(((CFG.user&&CFG.user.name)||'').split(/\s+/)[0])||'';
  const p=r.progress||{}, acts=r.activities||[], quotes=Number(r.quotes||0);
  const cur=p.currency||'USD', rem=Number(p.remaining||0), col=Number(p.collected||0), tot=Number(p.total||0), pct=Number(p.pct||0);
  const meInit=(CFG.user&&CFG.user.name)?initials(CFG.user.name):'·';
  const head='<div class="mt-hd"><div><div class="mt-d">'+esc(tdyDate())+'</div>'
    +'<div class="mt-g">'+esc(tdyGreeting())+(nm?', '+esc(nm):'')+'</div></div>'
    +'<div class="mt-hr"><span class="mt-sync" id="mtsync">● Synced</span><div class="mt-me">'+esc(meInit)+'</div></div></div>';
  const done = rem<=0.5 && tot>0.5;
  const hero = done
    ? '<div class="mt-hero done"><div class="mt-hk">Today’s collections</div><div class="mt-hv">Mission complete 🎉</div>'
      +'<div class="mt-hb"><i style="width:100%"></i></div><div class="mt-hm"><span><b>'+cur+' '+bmoney0(col)+'</b> collected</span><span>of <b>'+cur+' '+bmoney0(tot)+'</b></span></div></div>'
    : '<div class="mt-hero"><div class="mt-hk">Remaining to collect today</div><div class="mt-hv">'+cur+' '+bmoney0(rem)+'</div>'
      +'<div class="mt-hb"><i style="width:'+Math.max(3,Math.min(100,pct))+'%"></i></div>'
      +'<div class="mt-hm"><span><b>'+cur+' '+bmoney0(col)+'</b> collected</span><span>of <b>'+cur+' '+bmoney0(tot)+'</b></span></div></div>';
  const list = acts.length
    ? '<div class="mt-sec"><h3>To collect</h3></div>'+acts.map(mtCard).join('')
    : '<div class="mt-clear"><div class="mt-clear-ic">✓</div>All clear — nothing to chase right now.</div>';
  const waiting = quotes>0
    ? '<div class="mt-sec"><h3>Waiting</h3></div><div class="mt-pc" onclick="route(\'quotations\')"><div class="mt-av" style="background:#DD8500">'+mtIcon('file')+'</div>'
      +'<div class="mt-bd"><div class="mt-t">'+quotes+' quote'+(quotes>1?'s':'')+' waiting</div><div class="mt-s">confirm or follow up</div></div>'+mtChev()+'</div>'
    : '';
  const runBtn = (!done && acts.length)
    ? '<button class="mt-run" onclick="runStart()"><span class="mt-run-pl"><svg viewBox="0 0 24 24"><path d="M6 4l14 8-14 8V4z"/></svg></span>'
      +'<span class="mt-run-t"><span class="a">Start today’s collection run</span><span class="b">'+acts.length+' stop'+(acts.length>1?'s':'')+' · '+cur+' '+bmoney0(rem)+'</span></span>'
      +'<span class="mt-run-go"><svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></span></button>'
    : '';
  var celebrate='';
  if(window._RUN_DONE){ var rd=window._RUN_DONE; window._RUN_DONE=null;
    celebrate='<div class="mt-celebrate" id="mtcel">🎉 Collected '+rd.cur+' '+bmoney0(rd.amt)+' on your run · '+rd.count+' stop'+(rd.count>1?'s':'')+'</div>'; }
  v.innerHTML='<div class="mtoday">'+installBanner()+head+celebrate+hero+runBtn+list+waiting+'</div>';
  mtSync();
  var celEl=document.getElementById('mtcel');
  if(celEl){ if(window.__celTimer) clearTimeout(window.__celTimer);
    window.__celTimer=setTimeout(function(){ celEl.style.transition='opacity .5s,transform .5s'; celEl.style.opacity='0'; celEl.style.transform='translateY(-6px)';
      setTimeout(function(){ if(celEl&&celEl.parentNode) celEl.parentNode.removeChild(celEl); },520); },4500); }
}
function bmoney0(v){ return Number(v||0).toLocaleString(undefined,{maximumFractionDigits:0}); }
function mtChev(){ return '<span class="mt-chev"><svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg></span>'; }
function mtIcon(n){ return '<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>'; }
function mtCard(a){
  const name=a.name||'Customer', amt=Number(a.amount||0), st=a.status||'ok';
  const dotc = st==='overdue'?'#E24B3B':(st==='due'?'#DD8500':'#12A150');
  const ctx=[a.invoice_no, a.due_label, a.city].filter(Boolean).join(' · ');
  const link=(Number(a.customer_id)>0)?(' onclick="route(\'bos.timeline\','+Number(a.customer_id)+')"'):'';
  return '<div class="mt-pc"'+link+'><div class="mt-av" style="background:'+avColor(name)+'">'+esc(initials(name))+'</div>'
    +'<div class="mt-bd"><div class="mt-t"><span class="mt-dot" style="background:'+dotc+'"></span>'+esc(name)+'</div>'
    +'<div class="mt-s">'+esc(ctx)+'</div></div>'
    +'<div class="mt-r"><b>USD '+bmoney0(amt)+'</b><div class="mt-lab">Outstanding</div></div></div>';
}
async function mtSync(){
  var el=document.getElementById('mtsync'); if(!el) return;
  var items=[]; try{ items=await OB.all(); }catch(e){}
  var failed=items.filter(function(x){return x.status==='failed';}).length, n=items.length;
  if(!navigator.onLine){ el.textContent='● Offline'; el.className='mt-sync off'; }
  else if(failed>0){ el.textContent='● '+failed+' need attention'; el.className='mt-sync q'; }
  else if(n>0){ el.textContent='● '+n+' queued'; el.className='mt-sync q'; }
  else { el.textContent='● Synced'; el.className='mt-sync ok'; }
}
function tdyTone(t){ return t==='danger'?'danger':(t==='warn'?'warn':(t==='ok'?'ok':'')); }
/* Greeting + date from the PHONE's local clock, so they match the device (not the server timezone). */
function tdyGreeting(){ var h=new Date().getHours(); return h<12?'Good morning':(h<17?'Good afternoon':'Good evening'); }
function tdyDate(){ try{ return new Date().toLocaleDateString(undefined,{weekday:'long',day:'2-digit',month:'short'}); }catch(e){ return ''; } }
/* ===== "Add to Home Screen" hint — iPhone shows the Share tip; Android offers the native prompt.
   Hidden once installed (standalone) or dismissed. Purely additive; no effect on the app itself. ===== */
let OB_INSTALL=null;
try{ window.addEventListener('beforeinstallprompt', function(e){ e.preventDefault(); OB_INSTALL=e; }); }catch(e){}
function obStandalone(){ try{ return (window.matchMedia&&window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone===true; }catch(e){ return false; } }
function obInstallDismissed(){ try{ return localStorage.getItem('cbss_install_x')==='1'; }catch(e){ return false; } }
function obDismissInstall(){ try{ localStorage.setItem('cbss_install_x','1'); }catch(e){} const b=document.getElementById('instbar'); if(b) b.remove(); }
async function obInstall(){ if(OB_INSTALL){ try{ OB_INSTALL.prompt(); await OB_INSTALL.userChoice; }catch(e){} OB_INSTALL=null; obDismissInstall(); } }
function installBanner(){
  if(obStandalone() || obInstallDismissed()) return '';
  const isIOS=/iphone|ipad|ipod/i.test(navigator.userAgent||'');
  let sub, right;
  if(OB_INSTALL){ sub='Open it like an app — full screen, one tap.'; right='<button class="inst-go" onclick="obInstall()">Install</button>'; }
  else if(isIOS){ sub='Tap <b>Share</b> ⎋ then <b>Add to Home Screen</b>.'; right=''; }
  else { return ''; }
  return '<div class="instbar" id="instbar"><div class="inst-ic">📲</div>'
    +'<div class="inst-tx"><b>Add CloudBSS to your Home Screen</b><div class="inst-sub">'+sub+'</div></div>'
    +right+'<button class="inst-x" onclick="obDismissInstall()" aria-label="Dismiss">✕</button></div>';
}
function bosTodoRow(a){
  const link=(Number(a.customer_id)>0)?(' onclick="route(\'bos.timeline\','+Number(a.customer_id)+')" style="cursor:pointer"'):'';
  const badge=a.overdue?'<span class="tdy-od">overdue</span>':'';
  return '<div class="tdy-row"'+link+'><div class="tdy-ric">💵</div>'
    +'<div class="tdy-rb"><div class="tdy-rt">'+esc(a.title||'')+'</div><div class="tdy-rs">'+esc(a.sub||'')+'</div></div>'
    +badge+'<div class="tdy-rc">›</div></div>';
}
function closeNav(){ document.querySelector('.app').classList.remove('navopen'); document.getElementById('scrim').classList.remove('on'); }
function toggleAcct(e){ if(e) e.stopPropagation(); document.getElementById('acctMenu').classList.toggle('on'); }
function signOut(){ if(window.confirm('Sign out of CloudBSS?')) location.href='/panel/logout'; }
document.addEventListener('click',e=>{ if(!e.target.closest('.acct')) document.getElementById('acctMenu').classList.remove('on'); });

function skeleton(){ return '<div class="sk sk-head"></div><div class="kpis"><div class="sk sk-kpi"></div><div class="sk sk-kpi"></div><div class="sk sk-kpi"></div></div><div class="sk sk-card"></div>'; }
function emptyState(ic,h,s){ return '<div class="empty"><div class="eic">'+icon(ic,'ic-lg')+'</div><h4>'+esc(h)+'</h4><div>'+esc(s||'')+'</div></div>'; }

function renderView(p){
  if(p.table){ return renderList(p); }
  if(p.tabs && p.tabs.length){ return renderRecord(p); }
  const o=p.object||{}; const isProcess=(o.type||'').startsWith('process:');
  const cap=(p.meta&&p.meta.capability)||CUR.key;
  let h='';

  /* ---- header ---- */
  h+='<div class="crumb">'+(LABELS[cap]||cap)+(o.title&&!isProcess?' <span style="color:var(--faint)">/</span> '+esc(o.title):'')+'</div>';
  h+='<div class="rec-head">';
  if(!isProcess && o.image){ h+='<div class="rec-av" style="background:#eef0f3;background-image:url(\''+esc(o.image)+'\');background-size:cover;background-position:center"></div>'; }
  else if(!isProcess && o.title){ h+='<div class="rec-av" style="background:'+avColor(o.title)+'">'+initials(o.title)+'</div>'; }
  else { h+='<div class="rec-av" style="background:'+avColor(cap)+'">'+icon(cap,'ic-lg')+'</div>'; }
  h+='<div class="idn"><h1>'+esc(o.title||LABELS[cap]||cap)+' '+statusBadge(o.status)+'</h1>'+(o.subtitle?'<div class="sub">'+esc(o.subtitle)+'</div>':'')+'</div>';
  if(p.headline){ h+='<div class="rec-hl"><div class="v '+toneCls(p.headline.tone)+'">'+esc(p.headline.value)+'</div><div class="l">'+esc(p.headline.label)+'</div></div>'; }
  if((p.actions||[]).length){ h+='<div class="acts inl" style="margin-left:'+(p.headline?'12px':'auto')+'">'+p.actions.map(actBtn).join('')+'</div>'; }
  h+='</div>';

  if(isProcess){
    /* KPI cards */
    if(p.financials&&p.financials.length){
      h+='<div class="kpis">'+p.financials.map(f=>{ const tc=toneCls(f.tone);
        return '<div class="kpi"><div class="top"><span class="ib '+tc+'">'+icon(cap,'ic-sm')+'</span>'+esc(f.label)+'</div><div class="val '+tc+'">'+esc(fval(f))+'</div>'+(f.foot?'<div class="foot">'+esc(f.foot)+'</div>':'')+'</div>';
      }).join('')+'</div>';
    }
    h+=briefHtml(p.brief);
    const n=(p.related||[]).length;
    h+='<div class="card"><div class="h">'+icon(cap,'ic-sm')+(o.title||'Items')+(n?'<span class="n '+(o.tone||'')+'">'+n+'</span>':'')+'</div>'+
      (n? p.related.map(qrow).join('') : emptyState('check','All clear','Nothing pending in this queue.'))+'</div>';
  } else {
    h+=briefHtml(p.brief);
    if(Array.isArray(o.gallery) && o.gallery.length>1){
      h+='<div class="gstrip">'+o.gallery.map(u=>'<div class="gsimg" style="background-image:url(\''+esc(u)+'\')" onclick="window.open(\''+esc(u)+'\')"></div>').join('')+'</div>';
    }
    h+='<div class="rec">';
    /* rail */
    h+='<div class="rail">';
    if(p.financials&&p.financials.length){
      const f0=p.financials[0], rest=p.financials.slice(1);
      h+='<div class="card"><div class="b"><div class="rail-t">Financials</div><div class="rail-kpi">'+
        '<div><div class="big '+toneCls(f0.tone)+'">'+esc(fval(f0))+'</div><div class="r"><span class="l">'+esc(f0.label)+'</span></div></div>'+
        (rest.length?'<div class="rail-div"></div>':'')+
        rest.map(f=>'<div class="r"><span class="l">'+esc(f.label)+'</span><span class="v '+toneCls(f.tone)+'">'+esc(fval(f))+'</span></div>').join('')+
        '</div></div></div>';
    }
    /* quick actions from record actions (secondary copy in rail for reachability) */
    h+='</div>';
    /* main column */
    h+='<div class="grid">';
    if(p.timeline&&p.timeline.length){
      if(p.meta&&p.meta.thread){
        /* WhatsApp-style thread: inbound left, outbound right (bot = dashed), composer wired
           to the payload's own `reply` action — same endpoint, same body, no new write path. */
        const ract=(p.actions||[]).find(a=>a.key==='reply');
        window.__THREAD_REPLY__=ract||null;
        h+='<div class="card"><div class="h">'+icon('clock','ic-sm')+'Conversation</div><div class="b">'+
          '<div class="thread" id="thread">'+
          p.timeline.map(e=>{
            const out=e.type==='outbound';
            const bot=out&&(e.title||'').indexOf('\u{1F916}')>=0;
            return '<div class="msg '+(out?'out':'in')+(bot?' bot':'')+'">'+
              '<div class="who">'+esc(e.title||'')+'</div>'+esc(e.detail||'')+
              '<div class="when">'+esc(e.at||'')+'</div></div>';
          }).join('')+'</div>'+
          (ract?'<div class="composer"><input id="cmpin" placeholder="Type a reply…" autocomplete="off" onkeydown="if(event.key===\'Enter\')sendThread()"><button id="cmpbtn" onclick="sendThread()">Send</button></div>':'')+
          '</div></div>';
        setTimeout(function(){var el=document.getElementById('thread'); if(el) el.scrollTop=el.scrollHeight;},0);
      } else {
        h+='<div class="card"><div class="h">'+icon('clock','ic-sm')+'Timeline</div><div class="b"><div class="tl">'+
          p.timeline.map(e=>'<div class="e"><div class="node">'+icon('bolt','ic-sm')+'</div><div class="tt">'+esc(e.title)+'</div><div class="ts">'+esc(e.at||'')+(e.actor?' · '+esc(e.actor):'')+'</div></div>').join('')+
          '</div></div></div>';
      }
    }
    if(p.related&&p.related.length){
      h+='<div class="card"><div class="h">'+icon('worklist','ic-sm')+'Related</div>'+p.related.map(qrow).join('')+'</div>';
    }
    if(p.documents&&p.documents.length){
      h+='<div class="card"><div class="h">'+icon('doc','ic-sm')+'Documents</div><div class="b">'+
        p.documents.map(d=>'<div class="doc" onclick="window.open(\''+esc(d.url||'#')+'\')"><div class="di">'+icon('doc','ic-sm')+'</div><div><div class="dt">'+esc(d.title)+'</div></div></div>').join('')+
        '</div></div>';
    }
    if(!(p.timeline||[]).length && !(p.related||[]).length && !(p.documents||[]).length){
      h+='<div class="card"><div class="b">'+emptyState('check','No linked records','This record has no timeline or related items yet.')+'</div></div>';
    }
    h+='</div></div>';
  }
  document.getElementById('view').innerHTML=h;
}

function briefHtml(b){ if(!b) return '';
  const lvl=b.level||'low';
  let h='<div class="assist '+lvl+'"><div class="ai">✦</div><div class="body">';
  h+='<div class="sit">'+esc(b.situation)+(b.risk?'<span class="badge '+toneBadge(b.risk.tone)+' riskchip"><span class="d"></span>'+esc(b.risk.label)+'</span>':'')+'</div>';
  if(b.why) h+='<div class="why">'+esc(b.why)+'</div>';
  if(b.metrics&&b.metrics.length){ h+='<div class="astat">'+b.metrics.map(m=>'<div class="s"><div class="l">'+esc(m.label)+'</div><div class="v '+toneCls(m.tone)+'">'+esc(m.value)+'</div></div>').join('')+'</div>'; }
  if(b.recommendation) h+='<div class="arec"><div class="rl">'+icon('bolt','ic-sm')+'Recommendation</div>'+esc(b.recommendation)+'</div>';
  if(b.actions&&b.actions.length) h+='<div class="aacts">'+b.actions.map(actBtn).join('')+'</div>';
  h+='</div></div>';
  return h;
}
function qrow(r){ const link=r.link?(' onclick="openLink(\''+esc(r.link)+'\')"'):'';
  const right = r.sublabel && /[0-9]/.test(r.sublabel) ? r.sublabel : '';
  // Setup-checklist rows carry a boolean `done`: render a green tick (done) or a "Start" call-to-action (todo).
  const isStep = (typeof r.done === 'boolean');
  if(isStep){
    const qi = r.done
      ? '<div class="qi" style="background:var(--ok-wash);color:var(--brand-ink)">'+icon('check','ic-sm')+'</div>'
      : '<div class="qi">'+icon(r.type||'doc','ic-sm')+'</div>';
    const rr = r.done
      ? '<div class="r" style="color:var(--brand-ink)">Done</div>'
      : '<div class="r" style="color:var(--brand)">Start</div>';
    return '<div class="qrow"'+link+'>'+qi+
      '<div class="m"><div class="t"'+(r.done?' style="color:var(--muted)"':'')+'>'+esc(r.label||r.type)+'</div>'+
      (r.sublabel?'<div class="s">'+esc(r.sublabel)+'</div>':'')+'</div>'+
      rr+'<div class="chev">'+icon('chev','ic-sm')+'</div></div>';
  }
  return '<div class="qrow"'+link+'><div class="qi">'+icon(r.type||'doc','ic-sm')+'</div>'+
    '<div class="m"><div class="t">'+esc(r.label||r.type)+(r.count!=null?' <span style="color:var(--muted);font-weight:500">('+r.count+')</span>':'')+'</div>'+
    (r.sublabel&&!right?'<div class="s">'+esc(r.sublabel)+'</div>':'')+'</div>'+
    (right?'<div class="r">'+esc(right)+'</div>':'')+'<div class="chev">'+icon('chev','ic-sm')+'</div></div>'; }
// A row can navigate to a LIST pre-filtered two ways:
//   client:  "filter:<listKey>:<colKey>:<urlEncodedValue>"  → pre-selects a client-side dropdown.
//   server:  "scope:<listKey>:<param>:<urlEncodedValue>"     → the list is re-fetched with ?param=value
//            so the BACKEND filters (exact, case-insensitive) — used by category → products, like the panel.
let PENDING_FILTER=null, PENDING_SCOPE=null;
function openLink(apiPath){
  if(apiPath && apiPath.indexOf('scope:')===0){
    const p=apiPath.split(':'); PENDING_SCOPE={ key:p[1], query:p[2]+'='+(p[3]||'') }; route(p[1]); return;
  }
  if(apiPath && apiPath.indexOf('filter:')===0){
    const p=apiPath.split(':'); PENDING_FILTER={ key:p[2], val:decodeURIComponent(p[3]||'') }; route(p[1]); return;
  }
  const h=apiToHash(apiPath); if(h) route(h.key, h.id);
}

/* ============ record shell (tabbed 360: answers · health · KPIs · NBA · tabs) ============ */
const ACTICON={record_payment:'card',log_contact:'phone',log_whatsapp:'mail',edit_customer:'edit',update_customer:'edit',send_reminder:'bell',set_followup:'bell',assign_rep:'user',new_quotation:'quotations',open_invoice:'invoice',open_customer:'user',open_order:'cart',receipt_pdf:'download',quotation_pdf:'download',invoice_pdf:'download',send_receipt:'send',mark_delivered:'truck',create_invoice:'invoice'};
function actIcon(a){ return (a&&(a.icon||ACTICON[a.key]))||'chev'; }
function actJson(a){ return JSON.stringify(a).replace(/'/g,'&#39;'); }

function renderRecord(p){ LAST_REC=p; document.getElementById('view').innerHTML=recordHtml(p); hydrateCardTables(); try{ waCustCard(p); }catch(e){} try{ dealerCard(p); }catch(e){} }

/* ===== WhatsApp on the customer record (v4.4) =====
   One place for the customer's chat: tail + composer (posts the PROVEN /papi/chats/send,
   which also makes the bot yield the chat) + ✨ AI draft from the relationship memory.
   The AI only DRAFTS into the box — a human always reviews and presses Send. */
function recPhone(p){
  try{
    if(p&&p.object&&p.object.phone) return String(p.object.phone).replace(/[^0-9]/g,'');
    const m=JSON.stringify(p||{}).match(/"phone"\s*:\s*"\+?([0-9][0-9 ()-]{7,17})"/i);
    return m?m[1].replace(/[^0-9]/g,''):'';
  }catch(e){ return ''; }
}
/* v4.9 — Dealer programme 4a: grant/revoke trade pricing from the customer card (owner-only server-side). */
function dealerCard(p){
  const cap=(p&&p.meta&&p.meta.capability)||CUR.key;
  if(cap!=='customer'&&CUR.key!=='customers') return;
  const phone=recPhone(p); if(!phone) return;
  const v=document.getElementById('view'); if(!v||document.getElementById('dealerCard')) return;
  const d=document.createElement('div');
  d.className='card'; d.id='dealerCard'; d.style.marginTop='14px';
  d.innerHTML='<h3 style="margin:0 0 8px;font-size:15px">💼 Dealer pricing</h3><div id="dlBody" style="font-size:13px;color:#8a94a0">Loading…</div>';
  v.appendChild(d);
  dealerLoad(phone);
}
function dealerLoad(phone){
  getQuery('/papi/dealer',{phone:phone}).then(function(d){
    const el=document.getElementById('dlBody'); if(!el) return;
    if(!d||!d.ok){ el.textContent='Could not load dealer status.'; return; }
    if(d.migrated===false){ el.innerHTML='Run <code>php artisan migrate</code> to enable the dealer programme.'; return; }
    const tier=d.tier||''; const def=d.defaults||{};
    const badge= tier ? '<span style="background:'+(tier==='wholesale'?'#eef2ff;color:#3730a3':'#eaf6ee;color:#166534')+';font-weight:800;font-size:11px;border-radius:99px;padding:3px 10px">'+tier.toUpperCase()+(d.pct!=null?' · '+d.pct+'%':' · tier default')+'</span>'
                       : '<span style="background:#f1f3f5;color:#5b6572;font-weight:800;font-size:11px;border-radius:99px;padding:3px 10px">RETAIL CUSTOMER</span>';
    el.innerHTML='<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">'+badge+(d.since?'<span style="font-size:11px;color:#8a94a0">since '+esc(String(d.since).slice(0,10))+'</span>':'')+'</div>'
      +'<div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">'
      +'<select id="dlTier" style="padding:8px 10px;border:1px solid var(--line,#e2e6ea);border-radius:9px;font:inherit">'
        +'<option value=""'+(tier===''?' selected':'')+'>Retail (no dealer pricing)</option>'
        +'<option value="dealer"'+(tier==='dealer'?' selected':'')+'>Dealer — default '+(def.dealer!=null?def.dealer:10)+'%</option>'
        +'<option value="wholesale"'+(tier==='wholesale'?' selected':'')+'>Wholesale — default '+(def.wholesale!=null?def.wholesale:15)+'%</option></select>'
      +'<input id="dlPct" type="number" min="0" max="90" step="0.5" placeholder="custom %" value="'+(d.pct!=null?d.pct:'')+'" style="width:96px;padding:8px 10px;border:1px solid var(--line,#e2e6ea);border-radius:9px;font:inherit">'
      +'<button class="btn sm pri" onclick="dealerSave(\''+phone+'\')">Save</button></div>'
      +'<div style="font-size:11.5px;color:#8a94a0;margin-top:8px">Blank % = tier default. Floor: never below cost +'+(def.floor!=null?def.floor:5)+'%. The WhatsApp bot quotes their price automatically. Owner only.</div>';
  }).catch(function(){});
}
async function dealerSave(phone){
  const tier=(document.getElementById('dlTier')||{}).value||'';
  const pct=(document.getElementById('dlPct')||{}).value||'';
  const res=await post('/papi/dealer-set',{phone:phone,tier:tier,pct:pct});
  if(res&&res.ok){ toast(tier?('Dealer pricing saved — '+tier.toUpperCase()):'Back to retail'); dealerLoad(phone); }
  else toast((res&&(res.message||res.error))||'Could not save (owner only)',true);
}
function waCustCard(p){
  const cap=(p&&p.meta&&p.meta.capability)||CUR.key;
  if(cap!=='customer'&&CUR.key!=='customers') return;
  const phone=recPhone(p); if(!phone) return;
  const v=document.getElementById('view'); if(!v||document.getElementById('waCard')) return;
  const d=document.createElement('div');
  d.className='card'; d.id='waCard'; d.style.marginTop='14px';
  d.innerHTML='<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px">'
    +'<h3 style="margin:0;font-size:15px">💬 WhatsApp</h3>'
    +'<button class="btn sm" onclick="openChatFor(\''+phone+'\')">Open full chat</button></div>'
    +'<div id="waTail" style="max-height:260px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;padding:2px 0">Loading…</div>'
    +'<div style="display:flex;gap:8px;margin-top:10px;align-items:center">'
    +'<input id="waMsg" placeholder="Message to this customer…" style="flex:1;padding:10px 12px;border:1px solid var(--line,#e2e6ea);border-radius:10px;font:inherit" onkeydown="if(event.key===\'Enter\')waSendTo(\''+phone+'\')">'
    +'<button class="btn sm" onclick="waAiDraft(\''+phone+'\')" title="AI writes a personalised draft from what this customer is looking for — you review, then send">✨ AI draft</button>'
    +'<button class="btn sm pri" onclick="waSendTo(\''+phone+'\')">Send</button></div>';
  v.appendChild(d);
  waTailLoad(phone);
}
function waTailLoad(phone){
  getQuery('/papi/wa-thread',{phone:phone}).then(function(d){
    const el=document.getElementById('waTail'); if(!el) return;
    if(!d||!d.ok){ el.innerHTML='<span style="color:#8a94a0;font-size:13px">Could not load the conversation.</span>'; return; }
    const ms=d.messages||[];
    el.innerHTML=ms.length?ms.map(function(m){
      const out=m.dir!=='in';
      return '<div style="align-self:'+(out?'flex-end':'flex-start')+';max-width:82%;background:'+(out?'#dcf3e3':'#f1f3f5')+';border-radius:12px;padding:7px 11px;font-size:13px;line-height:1.4">'
        +esc(m.body||'')+'<div style="font-size:10px;color:#8a94a0;margin-top:2px;text-align:right">'+esc(m.at||'')+(out&&m.who?' · '+esc(m.who):'')+'</div></div>';
    }).join(''):'<span style="color:#8a94a0;font-size:13px">No WhatsApp messages with this customer yet — say hello below.</span>';
    el.scrollTop=el.scrollHeight;
    window.__WA_CONV=d.conversation_id||null;
  }).catch(function(){});
}
function openChatFor(phone){
  getQuery('/papi/wa-thread',{phone:phone}).then(function(d){
    route('chats',(d&&d.conversation_id)||null);
  }).catch(function(){ route('chats',null); });
}
async function waSendTo(phone){
  const inp=document.getElementById('waMsg'); const txt=(inp&&inp.value||'').trim(); if(!txt) return;
  inp.disabled=true;
  const res=await post('/papi/chats/send',{phone:phone,body:txt});
  inp.disabled=false;
  if(res&&res.ok){ inp.value=''; toast('Sent'); waTailLoad(phone); }
  else toast((res&&(res.error||res.message))||'Could not send',true);
}
async function waAiDraft(phone){
  const hint=window.prompt('What should this message achieve? (optional — e.g. "offer aluminum foil containers, 10% off this week")','')||'';
  const btns=document.querySelectorAll('#waCard .btn'); btns.forEach(function(b){b.disabled=true;});
  const res=await post('/papi/wa-ai-draft',{phone:phone,hint:hint});
  btns.forEach(function(b){b.disabled=false;});
  const inp=document.getElementById('waMsg');
  if(res&&res.ok&&res.draft&&inp){ inp.value=res.draft; inp.focus(); toast('Draft ready — edit if needed, then Send'); }
  else toast((res&&res.error)||'AI draft failed',true);
}
function setRecTab(k){ REC_TAB=k; if(LAST_REC){ document.getElementById('view').innerHTML=recordHtml(LAST_REC); hydrateCardTables(); } }

function recordHtml(p){
  window._CT=[];
  const o=p.object||{}, cap=(p.meta&&p.meta.capability)||CUR.key;
  const listKey=(p.meta&&p.meta.listKey)||(cap+'s');
  const tabs=p.tabs||[]; const tab=(tabs.find(t=>t.key===REC_TAB)?REC_TAB:(tabs[0]&&tabs[0].key));
  let h='<div class="crumb"><a onclick="route(\''+esc(listKey)+'\')">'+esc(LABELS[listKey]||listKey)+'</a> '+icon('chev','ic-sm')+' <span>'+esc(o.title||'')+'</span></div>';
  /* header */
  h+='<div class="rec-head">';
  if(o.image){ h+='<div class="rec-av" style="background:#eef0f3;background-image:url(\''+esc(o.image)+'\');background-size:cover;background-position:center"></div>'; }
  else { h+='<div class="rec-av" style="background:'+avColor(o.title||cap)+'">'+(o.title?initials(o.title):icon(cap,'ic-lg'))+'</div>'; }
  const segBadge=o.segment?' <span class="badge '+toneBadge('accent')+'"><span class="d"></span>'+esc(o.segment)+'</span>':'';
  h+='<div class="idn"><h1>'+esc(o.title||LABELS[cap]||cap)+' '+statusBadge(o.status)+segBadge+'</h1>'+(o.subtitle?'<div class="sub">'+esc(o.subtitle)+'</div>':'')+'</div>';
  if(p.headline){ h+='<div class="rec-hl"><div class="v '+toneCls(p.headline.tone)+'">'+esc(p.headline.value)+'</div><div class="l">'+esc(p.headline.label)+'</div></div>'; }
  h+='<div class="acts inl" style="margin-left:'+(p.headline?'12px':'auto')+'">'+(p.actions||[]).map(actBtn).join('')+moreHtml(p.more)+'</div>';
  h+='</div>';
  /* brief */
  h+=briefHtml(p.brief);
  /* rail + main */
  h+='<div class="rec"><div class="rail">'+railFinHtml(p.financials)+nbaHtml(p.nba)+qaHtml(p.quickActions)+'</div>';
  h+='<div><div class="tabs">'+tabs.map(t=>'<button class="'+(t.key===tab?'on':'')+'" onclick="setRecTab(\''+esc(t.key)+'\')">'+esc(t.label)+(t.count!=null?' <span class="n">'+t.count+'</span>':'')+'</button>').join('')+'</div>';
  h+='<div>'+tabContent(p,tab)+'</div></div></div>';
  return h;
}

function railFinHtml(fins){ if(!fins||!fins.length) return '';
  const f0=fins[0], rest=fins.slice(1);
  return '<div class="card"><div class="b"><div class="rail-kpi">'+
    '<div class="big '+toneCls(f0.tone)+'">'+esc(fval(f0))+'</div><div class="r" style="margin-bottom:4px"><span class="l">'+esc(f0.label)+'</span></div>'+
    (rest.length?'<div class="rail-div"></div>':'')+
    rest.map(f=>'<div class="r"><span class="l">'+esc(f.label)+'</span><span class="v '+toneCls(f.tone)+'">'+esc(fval(f))+'</span></div>').join('')+
    '</div></div></div>'; }
function nbaHtml(n){ if(!n) return '';
  return '<div class="nba"><div class="t">'+icon('trend','ic-sm')+'Next best action</div><div class="m">'+esc(n.text)+'</div>'+
    (n.action?'<button class="btn pri sm" onclick=\'doAction('+actJson(n.action)+')\'>'+esc(n.action.label)+'</button>':'')+'</div>'; }
function qaHtml(list){ if(!list||!list.length) return '';
  return '<div class="card"><div class="b"><div class="rail-t">Quick actions</div><div class="qa">'+
    list.map(a=>'<button onclick=\'doAction('+actJson(a)+')\'>'+icon(actIcon(a),'ic-sm')+esc(a.label)+'</button>').join('')+'</div></div></div>'; }
function moreHtml(more){ if(!more||!more.length) return '';
  return '<div class="moremenu"><button class="btn btn-ic" onclick="toggleMenu(this)">'+icon('dots','ic-sm')+'</button><div class="menu" style="display:none">'+
    more.map(a=>'<button class="'+(a.tone==='danger'?'dgr':'')+'" onclick=\'closeMenus();doAction('+actJson(a)+')\'>'+icon(actIcon(a),'ic-sm')+esc(a.label)+'</button>').join('')+'</div></div>'; }
function toggleMenu(btn){ const m=btn.parentElement.querySelector('.menu'); const open=m.style.display==='block'; closeMenus(); m.style.display=open?'none':'block'; }
function closeMenus(){ document.querySelectorAll('.moremenu .menu').forEach(m=>m.style.display='none'); }
document.addEventListener('click',e=>{ if(!e.target.closest('.moremenu')) closeMenus(); });

function tabContent(p,tab){
  if(tab==='timeline') return timelineTab(p.timeline);
  if(tab==='financials') return financialsTab(p);
  if(tab==='documents') return docsTab(p.documents);
  if(p.tabSections && p.tabSections[tab]){ return sectionCards(p.tabSections[tab]); }
  /* overview (default) */
  let h='';
  h+=pipelineHtml(p.pipeline);
  if(p.stats&&p.stats.length){ h+='<div class="stat-grid">'+p.stats.map(f=>{ const tc=toneCls(f.tone);
    return '<div class="kpi"><div class="top"><span class="ib '+tc+'">'+icon(f.icon||'bolt','ic-sm')+'</span>'+esc(f.label)+'</div><div class="val '+tc+'">'+esc(f.value)+'</div>'+(f.foot?'<div class="foot">'+esc(f.foot)+'</div>':'')+'</div>';
  }).join('')+'</div>'; }
  if(p.answers&&p.answers.length){ h+='<div class="answers">'+p.answers.map(a=>
    '<div class="ans"><div class="q">'+esc(a.q)+'</div><div class="a '+(a.tone||'')+'">'+esc(a.value)+'</div>'+(a.sub?'<div class="subq">'+esc(a.sub)+'</div>':'')+'</div>').join('')+'</div>'; }
  if(p.health){ h+=healthHtml(p.health); }
  (p.overviewCards||[]).forEach(c=>{ h+='<div style="height:14px"></div>'+overviewCard(c); });
  return h;
}
function sectionCards(cards){
  if(!cards||!cards.length) return '<div class="card"><div class="b">'+emptyState('worklist','Nothing here yet','')+'</div></div>';
  return cards.map((c,i)=>(i?'<div style="height:14px"></div>':'')+overviewCard(c)).join('');
}
function healthHtml(hh){
  return '<div class="card" style="margin-top:14px"><div class="card-h"><h3>'+esc(hh.title||'Health')+'</h3></div><div class="b"><div class="hgrid">'+
    healthRing(hh.score,74)+'<dl class="hmeta">'+(hh.meta||[]).map(m=>'<dt>'+esc(m.label)+'</dt><dd class="'+toneCls(m.tone)+'">'+esc(m.value)+'</dd>').join('')+'</dl></div></div></div>'; }
function healthRing(score,size){ size=size||64; score=Math.max(0,Math.min(100,Number(score)||0)); const r=size/2-6; const C=2*Math.PI*r;
  const col=score>=75?'#16a34a':score>=50?'#d97706':'#dc2626'; const off=C*(1-score/100);
  return '<div class="hring" style="width:'+size+'px;height:'+size+'px"><svg width="'+size+'" height="'+size+'"><circle cx="'+(size/2)+'" cy="'+(size/2)+'" r="'+r+'" fill="none" stroke="#eef0f3" stroke-width="6"/><circle cx="'+(size/2)+'" cy="'+(size/2)+'" r="'+r+'" fill="none" stroke="'+col+'" stroke-width="6" stroke-linecap="round" stroke-dasharray="'+C.toFixed(1)+'" stroke-dashoffset="'+off.toFixed(1)+'" transform="rotate(-90 '+(size/2)+' '+(size/2)+')"/></svg><div class="hs" style="color:'+col+'">'+score+'</div></div>'; }

function overviewCard(c){
  const head='<div class="card-h"><h3>'+esc(c.title||'')+'</h3>'+(c.sub?'<span class="sub">'+esc(c.sub)+'</span>':'')+'</div>';
  if(c.kind==='dl'){
    return '<div class="card">'+head+'<div class="b"><dl class="dl">'+(c.rows||[]).map(r=>'<dt>'+esc(r[0])+'</dt><dd>'+esc(r[1])+'</dd>').join('')+'</dl></div></div>';
  }
  if(c.kind==='table'){
    // Register the rows; the toolbar (search / date range) + pagination render after the card is in the DOM.
    const idx=(window._CT=window._CT||[]).length;
    window._CT.push({cols:c.columns||[], rows:c.rows||[], empty:c.empty||'Nothing here', page:1, ps:c.pageSize||10,
      searchable:c.searchable!==false, dateFilter:!!c.dateFilter, q:'', from:'', to:''});
    return '<div class="card">'+head+'<div id="ct_'+idx+'"></div></div>';
  }
  if(c.kind==='links'){
    if(!(c.items||[]).length) return '<div class="card">'+head+'<div class="b">'+emptyState('check',c.empty||'Nothing here','')+'</div></div>';
    return '<div class="card">'+head+'<div class="b">'+(c.items||[]).map(it=>
      '<div class="linkcard"'+(it.link?' onclick="openLink(\''+esc(it.link)+'\')"':'')+'><div class="lci" style="background:'+esc(it.color||'#16a34a')+'1e;color:'+esc(it.color||'#16a34a')+'">'+icon(it.icon||'doc','ic-sm')+'</div>'+
      '<div class="lct"><div class="t">'+esc(it.title)+'</div>'+(it.sub?'<div class="s">'+esc(it.sub)+'</div>':'')+'</div>'+
      (it.right?'<div class="lcr '+toneCls(it.rightTone)+'">'+esc(it.right)+'</div>':'')+'</div>').join('')+'</div></div>';
  }
  return '';
}
// Shared renderer for per-row action buttons (href opens; post fires a mutation then refreshes).
function rowActBtnsHtml(buttons){ return (buttons||[]).map(bn=>{ const ic=icon(bn.icon||'dots','ic-sm'); const tn=bn.tone==='danger'?' dgr':''; const ttl=esc(bn.title||'');
  if(bn.href) return '<button class="rowbtn'+tn+'" title="'+ttl+'" onclick="event.stopPropagation();try{window.open(\''+esc(bn.href)+'\',\'_blank\')}catch(e){location.href=\''+esc(bn.href)+'\'}">'+ic+'</button>';
  if(bn.action) return '<button class="rowbtn'+tn+'" title="'+ttl+'" onclick=\'event.stopPropagation();doAction('+JSON.stringify(bn.action).replace(/'/g,"&#39;")+')\'>'+ic+'</button>';
  return '<button class="rowbtn'+tn+'" title="'+ttl+'" onclick=\'event.stopPropagation();rowPost('+JSON.stringify({endpoint:bn.post,body:bn.body||{},confirm:bn.confirm||""}).replace(/'/g,"&#39;")+')\'>'+ic+'</button>'; }).join(''); }
function cellBadge(cell){ if(cell&&typeof cell==='object'){ if(cell.buttons&&cell.buttons.length) return '<div class="rowacts">'+rowActBtnsHtml(cell.buttons)+'</div>'; if(cell.badge) return '<span class="badge '+toneBadge(cell.badge)+'"><span class="d"></span>'+esc(cell.value)+'</span>'; if(cell.tone){ const col=cell.tone==='danger'?'var(--danger)':(cell.tone==='ok'?'var(--brand-ink)':(cell.tone==='warn'?'var(--warn-ink,#b45309)':'inherit')); return '<span style="color:'+col+';font-weight:600">'+esc(cell.value)+'</span>'; } return esc(cell.value); } return esc(cell); }
function hydrateCardTables(){ (window._CT||[]).forEach((t,i)=>cardTableRender(i)); }
function ctText(cell){ return (cell&&typeof cell==='object')?(cell.value!=null?cell.value:''):(cell!=null?cell:''); }
// Full render: draws the toolbar ONCE (so its inputs keep focus) + a body container the filters re-render into.
function cardTableRender(i){
  const t=(window._CT||[])[i]; const el=document.getElementById('ct_'+i); if(!t||!el) return;
  const showSearch = t.searchable && t.rows.length>8;
  let tb='';
  if(showSearch || t.dateFilter){
    tb='<div class="cttool">';
    if(showSearch) tb+='<div class="tsearch"><svg class="ic ic-sm" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg><input placeholder="Search…" value="'+esc(t.q||'')+'" oninput="cardTableSet('+i+',\'q\',this.value)"></div>';
    if(t.dateFilter) tb+='<label class="chip">From <input type="date" value="'+esc(t.from||'')+'" onchange="cardTableSet('+i+',\'from\',this.value)"></label>'+
      '<label class="chip">To <input type="date" value="'+esc(t.to||'')+'" onchange="cardTableSet('+i+',\'to\',this.value)"></label>'+
      ((t.from||t.to)?'<button class="btn sm" onclick="cardTableClear('+i+')">Clear</button>':'');
    tb+='</div>';
  }
  el.innerHTML=tb+'<div id="ctb_'+i+'"></div>';
  cardTableBody(i);
}
// Light render: filter + paginate into the body container only (keeps toolbar focus).
function cardTableBody(i){
  const t=(window._CT||[])[i]; const el=document.getElementById('ctb_'+i); if(!t||!el) return;
  let rows=t.rows.slice();
  const q=(t.q||'').trim().toLowerCase();
  if(q) rows=rows.filter(r=>(r.cells||[]).some(c=>String(ctText(c)).toLowerCase().includes(q)));
  if(t.dateFilter){ const f=t.from?Math.floor(Date.parse(t.from)/1000):null; const to=t.to?Math.floor(Date.parse(t.to)/1000)+86399:null;
    if(f!=null||to!=null) rows=rows.filter(r=>{ const s=Number(r.ts)||0; if(f!=null&&s<f) return false; if(to!=null&&s>to) return false; return true; }); }
  if(!rows.length){ el.innerHTML='<div class="b">'+emptyState('doc',(q||t.from||t.to)?'No matching rows':t.empty,'')+'</div>'; return; }
  const ps=t.ps, pages=Math.max(1,Math.ceil(rows.length/ps)); if(t.page>pages) t.page=pages; if(t.page<1) t.page=1;
  const start=(t.page-1)*ps, pageRows=rows.slice(start,start+ps);
  const thead='<tr>'+t.cols.map(col=>'<th'+(col.align==='right'?' class="num"':'')+'>'+esc(col.label)+'</th>').join('')+'</tr>';
  const body=pageRows.map(r=>'<tr class="'+(r.link?'clk':'')+'"'+(r.link?' onclick="openLink(\''+esc(r.link)+'\')"':'')+'>'+
    (r.cells||[]).map((cell,ci)=>{ const al=t.cols[ci]&&t.cols[ci].align==='right'?' class="num"':''; return '<td'+al+'>'+cellBadge(cell)+'</td>'; }).join('')+'</tr>').join('');
  let h='<div style="overflow-x:auto"><table class="dtbl"><thead>'+thead+'</thead><tbody>'+body+'</tbody></table></div>';
  if(rows.length>ps){ h+='<div class="pager"><span>'+(start+1)+'–'+Math.min(start+ps,rows.length)+' of '+fmtCount(rows.length)+'</span>'+
    '<button onclick="cardTablePage('+i+',-1)"'+(t.page<=1?' disabled':'')+'>Prev</button><span>Page '+t.page+' / '+pages+'</span>'+
    '<button onclick="cardTablePage('+i+',1)"'+(t.page>=pages?' disabled':'')+'>Next</button></div>'; }
  el.innerHTML=h;
}
function cardTableSet(i,k,v){ const t=(window._CT||[])[i]; if(!t) return; t[k]=v; t.page=1; if(k==='from'||k==='to') cardTableRender(i); else cardTableBody(i); }
function cardTableClear(i){ const t=(window._CT||[])[i]; if(!t) return; t.from=''; t.to=''; t.page=1; cardTableRender(i); }
function cardTablePage(i,d){ const t=(window._CT||[])[i]; if(!t) return; t.page+=d; cardTableBody(i); }

function timelineTab(events){
  if(!events||!events.length) return '<div class="card"><div class="b">'+emptyState('clock','No activity yet','This customer has no recorded activity.')+'</div></div>';
  return '<div class="card"><div class="b"><div class="tl">'+events.map(e=>
    '<div class="e"><div class="node">'+icon('bolt','ic-sm')+'</div><div class="tt">'+esc(e.title||'')+'</div><div class="ts">'+esc(e.at||'')+(e.actor?' · '+esc(e.actor):'')+'</div></div>').join('')+'</div></div></div>';
}
function stmtHtml(st){
  if(!st||!(st.rows||[]).length) return '<div class="card"><div class="b">'+emptyState('book','No transactions','No invoices or payments recorded yet.')+'</div></div>';
  return '<div class="card"><div class="card-h"><h3>Statement of account</h3></div><div style="overflow-x:auto"><table class="stmt"><thead><tr><th>Date</th><th>Detail</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th></tr></thead><tbody>'+
    st.rows.map(r=>'<tr class="'+(r.link?'clk':'')+'"'+(r.link?' onclick="openLink(\''+esc(r.link)+'\')"':'')+'><td>'+esc(r.date)+'</td><td>'+esc(r.detail)+'</td><td class="num">'+esc(r.debit||'')+'</td><td class="num">'+esc(r.credit||'')+'</td><td class="num">'+esc(r.balance)+'</td></tr>').join('')+
    '<tr class="bal"><td colspan="4">'+esc(st.balanceLabel||'Balance')+'</td><td class="num">'+esc(st.balanceValue)+'</td></tr>'+
    '</tbody></table></div></div>';
}
function pipelineHtml(stages){ if(!stages||!stages.length) return '';
  return '<div class="pipe">'+stages.map(s=>'<div class="pst '+esc(s.state||'wait')+'"><div class="pc">'+(s.state==='done'?icon('check','ic-sm'):'')+'</div><div class="pl">'+icon(s.icon||'chev','ic-sm')+esc(s.label)+'</div><div class="pv">'+esc(s.value||'')+'</div></div>').join('')+'</div>'; }
function financialsTab(p){
  let h='';
  if(p.statement && p.statement.rows && p.statement.rows.length) h+=stmtHtml(p.statement);
  (p.financialsCards||[]).forEach(c=>{ h+=(h?'<div style="height:14px"></div>':'')+overviewCard(c); });
  if(!h) h=stmtHtml(p.statement);
  return h;
}
function docsTab(docs){
  if(!docs||!docs.length) return '<div class="card"><div class="b">'+emptyState('doc','No documents','Invoices and statements will appear here.')+'</div></div>';
  return '<div class="card"><div class="h">'+icon('doc','ic-sm')+'Documents</div><div class="b">'+docs.map(d=>'<div class="doc" onclick="window.open(\''+esc(d.url||'#')+'\')"><div class="di">'+icon('doc','ic-sm')+'</div><div><div class="dt">'+esc(d.title)+'</div></div></div>').join('')+'</div></div>';
}

function actBtn(a){ const cls=a.actor==='ai'?'btn ai':('btn'+(a.tone==='primary'?' pri':(a.tone==='danger'?' danger':'')));
  return '<button class="'+cls+'" onclick=\'doAction('+JSON.stringify(a).replace(/'/g,"&#39;")+')\'>'+(a.actor==='ai'?'✦ ':'')+esc(a.label)+'</button>'; }
/* Append the live table scope (date range + active filter chips) to an export href so a download
   always matches exactly what the user is looking at. exportScope maps TBL filter keys → query params. */
function withScope(a){
  let url=a.href; const es=a.exportScope; if(!es) return url;
  const params=[]; const add=(k,v)=>{ if(v!=null&&v!=='') params.push(encodeURIComponent(k)+'='+encodeURIComponent(v)); };
  if(typeof TBL!=='undefined' && TBL){
    if(es.dateFrom && TBL.from) add(es.dateFrom, TBL.from);
    if(es.dateTo && TBL.to) add(es.dateTo, TBL.to);
    const fv=TBL.fval||{};
    Object.keys(es.filters||{}).forEach(k=>{ const sel=fv[k]; if(!sel||sel==='All') return; const spec=es.filters[k];
      if(typeof spec==='string'){ add(spec, sel); }
      else if(spec&&spec.param){ const v=spec.map?spec.map[sel]:sel; add(spec.param, v); } });
  }
  if(params.length) url += (url.indexOf('?')<0?'?':'&')+params.join('&');
  return url;
}
async function doAction(a){
  if(a.href){ const u=withScope(a); try{ window.open(u,'_blank'); }catch(e){ location.href=u; } return; }
  if(a.statement){ return statementModal(a); }
  if(a.chooser){ return chooserModal(a); }
  // queryWrite: reuse a legacy panel GET endpoint. With a schema → open the form (submit builds the
  // query string); without → an optional confirm then a direct GET-query write, then refresh.
  if(a.queryWrite){
    if(a.schema){ return formModal(a); }
    if(a.confirm && !window.confirm(a.confirm)) return;
    const res=await getQuery(a.endpoint, a.body||{});
    toast(res&&res.ok?(res.message||'Done'):((res&&(res.message||res.error))||'Error'), !(res&&res.ok)); refresh(); return;
  }
  if((a.method||'POST')==='GET'){ const h=apiToHash(a.endpoint); if(h){ route(h.key,h.id); } return; }
  if(a.builder==='quotation'){ return quoteBuilder(a); }
  if(a.schema){ return formModal(a); }
  if(a.confirm && !window.confirm(a.confirm)) return;
  const ep=a.endpoint||a.post; if(!ep){ return; }   // accept `post:` (menu/row delete actions) as well as `endpoint:`
  const res=await post(ep,a.body||{}); toast(res.ok?(res.message||'Done'):(res.message||res.error||'Error'), !res.ok);
  if(res&&res.ok){ if(res.pdf_b64){ downloadB64(res.pdf_b64,res.filename||'document.pdf'); } else if(res.url){ try{ window.open(res.url,'_blank'); }catch(e){} } }
  refresh();
}
/* 🌐 translate one inbound message: one AI call ever (server caches on the row). */
async function trMsg(id){
  const res=await post('/papi/chats/translate',{message_id:id});
  if(res&&res.ok){ if(window.__WA.active) openThread(window.__WA.active); }
  else toast((res&&res.error==='ai_not_configured')?'AI key not configured':'Could not translate', true);
}
/* 📍 quick-send: your current position via browser geolocation; falls back to the
   manual lat/lng form (the send_location action) if permission is denied. */
function sendLoc(){
  const a=window.__WA.locAct; if(!a) return;
  if(!navigator.geolocation){ doAction(a); return; }
  navigator.geolocation.getCurrentPosition(async function(pos){
    if(!window.confirm('Send your current location to this customer?')){ return; }
    const body=Object.assign({},a.body||{});
    body.lat=pos.coords.latitude; body.lng=pos.coords.longitude; body.name='My location';
    const res=await post(a.endpoint,body);
    toast(res&&res.ok?'Location sent':((res&&(res.message||res.error))||'Could not send'), !(res&&res.ok));
    refresh();
  }, function(){ doAction(a); }, {enableHighAccuracy:true, timeout:8000});
}
/* Thread composer: posts through the payload's reply action (proven /papi/chats/send). */
async function sendThread(){
  const a=window.__THREAD_REPLY__; const inp=document.getElementById('cmpin'); const btn=document.getElementById('cmpbtn');
  if(!a||!inp) return;
  let txt=(inp.value||'').trim(); if(!txt) return;
  /* reply-in-Arabic mode: translate the draft, show it, confirm, send the Arabic */
  if(window.__WA.arOut){
    const tr=await post('/papi/chats/translate-out',{text:txt});
    if(tr&&tr.ok&&tr.translation){
      if(!window.confirm('Send in Arabic:\n\n'+tr.translation)) return;
      txt=tr.translation;
    } else if(!window.confirm('Could not translate — send in English instead?')){ return; }
  }
  inp.disabled=true; if(btn) btn.disabled=true;
  const body=Object.assign({},a.body||{}); body.body=txt;
  const res=await post(a.endpoint,body);
  toast(res&&res.ok?'Sent':((res&&(res.message||res.error))||'Could not send'), !(res&&res.ok));
  refresh();
}
/* Per-row list action: confirm (optional) → POST → toast → refresh the list. */
async function rowPost(o){
  if(!o||!o.endpoint) return;
  if(o.confirm && !window.confirm(o.confirm)) return;
  const res=await post(o.endpoint,o.body||{});
  toast(res.ok?(res.message||'Done'):(res.message||res.error||'Error'), !res.ok);
  refresh();
}
/* Turn a base64 payload into a real file download — avoids any dependency on the server's public URL/domain. */
function downloadB64(b64,filename){
  try{ const bin=atob(b64); const arr=new Uint8Array(bin.length); for(let i=0;i<bin.length;i++) arr[i]=bin.charCodeAt(i);
    const blob=new Blob([arr],{type:'application/pdf'}); const url=URL.createObjectURL(blob);
    const a=document.createElement('a'); a.href=url; a.download=filename||'document.pdf'; document.body.appendChild(a); a.click(); a.remove();
    setTimeout(()=>URL.revokeObjectURL(url),5000);
  }catch(e){ toast('Could not open the PDF', true); }
}
// Guided "What happened?" recorder: a tile picker. Each tile carries its own action (a form),
// so the user taps the event first, then only fills the few fields that event needs.
function chooserModal(a){
  window.__CHOOSER__=(a.chooser||[]).map(t=>t.action);
  const tiles=(a.chooser||[]).map((t,i)=>
    '<button type="button" class="chtile" onclick="chooserPick('+i+')">'+
    '<span class="chic '+(t.tone==='in'?'chin':(t.tone==='out'?'chout':(t.tone==='move'?'chmove':'')))+'">'+icon(t.icon||'dots','')+'</span>'+
    '<span class="chtx"><span class="cht">'+esc(t.title)+'</span>'+(t.sub?'<span class="chs">'+esc(t.sub)+'</span>':'')+'</span>'+
    '<svg class="ic chev" viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></button>').join('');
  document.getElementById('modal').innerHTML='<div class="h">'+esc(a.label||'What happened?')+'<button class="x" onclick="closeM()"><svg class="ic" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>'+
    '<div class="b"><div class="chgrid">'+tiles+'</div></div>';
  document.getElementById('ov').classList.add('on');
}
function chooserPick(i){ const act=(window.__CHOOSER__||[])[i]; closeM(); if(act) setTimeout(()=>doAction(act),60); }
// Statement download picker: choose currency + PDF/CSV + an optional date range, then open the doc.
// The endpoint (…/doc/supplier-statement) already accepts id/currency/format/from/to as query params.
function statementModal(a){
  const st=a.statement||{}; const curs=(st.currencies&&st.currencies.length)?st.currencies:['USD'];
  const curOpts=curs.map(c=>'<option value="'+esc(c)+'">'+esc(c)+'</option>').join('');
  document.getElementById('modal').innerHTML=
    '<div class="h">Download statement'+(st.name?' — '+esc(st.name):'')+'<button class="x" onclick="closeM()"><svg class="ic" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div>'+
    '<div class="b">'+
      '<div class="fld"><label>Currency</label><select id="stm_cur">'+curOpts+'</select></div>'+
      '<div class="fld"><label>Format</label><select id="stm_fmt"><option value="pdf">PDF</option><option value="csv">CSV (Excel)</option></select></div>'+
      '<div class="fld"><label>From <span class="mut" style="font-weight:400">(optional)</span></label><input type="date" id="stm_from"></div>'+
      '<div class="fld"><label>To <span class="mut" style="font-weight:400">(optional)</span></label><input type="date" id="stm_to"></div>'+
      '<div class="mut" style="font-size:12px;margin-bottom:12px">Leave the dates empty for the full history. Anything before the "From" date is rolled into the opening balance.</div>'+
      '<button class="btn pri" onclick="statementGo('+(parseInt(st.id,10)||0)+')">Download</button>'+
    '</div>';
  document.getElementById('ov').classList.add('on');
}
function statementGo(id){
  const cur=(document.getElementById('stm_cur')||{}).value||'USD';
  const fmt=(document.getElementById('stm_fmt')||{}).value||'pdf';
  const from=(document.getElementById('stm_from')||{}).value||'';
  const to=(document.getElementById('stm_to')||{}).value||'';
  if(from&&to&&from>to){ toast('The "From" date is after the "To" date.',true); return; }
  let u='/api/v1/doc/supplier-statement?id='+encodeURIComponent(id)+'&currency='+encodeURIComponent(cur)+'&format='+encodeURIComponent(fmt);
  if(from) u+='&from='+encodeURIComponent(from);
  if(to) u+='&to='+encodeURIComponent(to);
  closeM();
  try{ window.open(u,'_blank'); }catch(e){ location.href=u; }
}
window.GAL={};
function formModal(a){
  const pf=a.prefill||{};
  window.GAL={};
  let fields=''; Object.entries(a.schema).forEach(([k,type])=>{
    const lbl=(a.labels&&a.labels[k]!=null)?a.labels[k]:k.replace(/_/g,' '); const val=pf[k]!=null?String(pf[k]):'';
    if(type==='gallery'){
      window.GAL[k]=Array.isArray(pf[k])?pf[k].slice():[];
      fields+='<div class="fld"><label>'+lbl+' <span class="mut" style="font-weight:400">(first photo is the main one)</span></label><div class="gallery" id="gal_'+k+'"></div>'+
        '<input type="file" accept="image/*" id="galf_'+k+'" style="display:none" onchange="galUpload(\''+k+'\')"></div>'; }
    else if(type==='image'){
      fields+='<div class="fld"><label>'+lbl+'</label><div class="imgfld">'+
        '<div class="imgprev" id="prev_'+k+'"'+(val?' style="background-image:url(\''+esc(val)+'\')"':'')+'></div>'+
        '<div class="imgbtns"><input type="hidden" id="f_'+k+'" value="'+esc(val)+'">'+
        '<input type="file" accept="image/*" id="file_'+k+'" style="display:none" onchange="uploadPhoto(\''+k+'\')">'+
        '<button type="button" class="btn sm" onclick="document.getElementById(\'file_'+k+'\').click()">'+icon('doc','ic-sm')+'Choose photo</button>'+
        '<span class="mut" id="up_'+k+'" style="font-size:12px"></span></div></div></div>'; }
    else if(type==='file' || String(type).startsWith('file:')){
      const acc=String(type).startsWith('file:')?String(type).slice(5):'';
      fields+='<div class="fld"><label>'+lbl+'</label><input type="hidden" id="f_'+k+'" value="">'+
        '<input type="file" '+(acc?('accept="'+esc(acc)+'"'):'')+' id="upl_'+k+'" onchange="uploadFileField(\''+k+'\')" style="font:inherit;font-size:13px">'+
        '<span class="mut" id="upls_'+k+'" style="font-size:12px;display:block;margin-top:5px"></span></div>'; }
    else if(type==='mediapick'){
      // Photo you can (a) pick from your products — its image + name/price attach automatically,
      // (b) upload from the device, or (c) paste a URL. Chosen products ride along as product_ids.
      window.MP=window.MP||{};
      window.MP[k]={url:val, products:(a.attachedProducts||[]).slice(),
        searchEp:a.mediaSearch||'/api/v1/workspace/products/_/search',
        uploadEp:a.mediaUpload||'/api/v1/workspace/products/_/upload_image'};
      fields+='<div class="fld"><label>'+lbl+'</label>'+
        '<div class="mp">'+
          '<div class="imgprev mp-prev" id="prev_'+k+'" style="'+cssBg(val)+'"></div>'+
          '<div class="mp-side">'+
            '<div class="mp-search"><input id="mpq_'+k+'" placeholder="Search your products…" autocomplete="off" oninput="mpSearch(\''+k+'\')"><div class="qb-res" id="mpres_'+k+'"></div></div>'+
            '<div class="mp-btns">'+
              '<input type="file" accept="image/*" id="file_'+k+'" style="display:none" onchange="mpUpload(\''+k+'\')">'+
              '<button type="button" class="btn sm" onclick="document.getElementById(\'file_'+k+'\').click()">'+icon('doc','ic-sm')+'Upload photo</button>'+
              '<span class="mut" id="up_'+k+'" style="font-size:12px"></span>'+
            '</div>'+
            '<input id="f_'+k+'" value="'+esc(val)+'" placeholder="…or paste an image link" oninput="mpSync(\''+k+'\')" style="width:100%;font-size:12.5px;padding:7px 9px;border:1px solid var(--line);border-radius:8px;color:var(--muted)">'+
          '</div>'+
        '</div>'+
        '<div class="mp-chips" id="mpchips_'+k+'"></div></div>'; }
    else if(String(type).startsWith('enum:')){ const opts=String(type).slice(5).split(',');
      fields+='<div class="fld"><label>'+lbl+'</label><select id="f_'+k+'">'+opts.map(o=>'<option'+(o===val?' selected':'')+'>'+esc(o)+'</option>').join('')+'</select></div>'; }
    else if(String(type).startsWith('perday:')){ const opts=String(type).slice(7).split(','); const cur=String(val==null?'0':val);
      /* Preset "send X per day" buttons — write a hidden f_<k> the generic save already reads, so a big
         audience trickles out over days instead of a single blast (safer for the number). */
      fields+='<div class="fld"><label>'+lbl+'</label>'+
        '<div class="set-segs" id="pd_'+k+'">'+opts.map(function(o){ var lb=(o==='0')?'All at once':(o+' / day'); return '<button type="button" class="set-seg'+(o===cur?' on':'')+'" data-v="'+esc(o)+'" onclick="pdPick(\''+k+'\',\''+esc(o)+'\')">'+esc(lb)+'</button>'; }).join('')+'</div>'+
        '<input type="hidden" id="f_'+k+'" value="'+esc(cur)+'">'+
        '<div class="mut" id="pdplan_'+k+'" style="font-size:12px;margin-top:6px"></div></div>'; }
    else if(String(type).startsWith('combo:')){ const opts=String(type).slice(6).split('|').filter(Boolean);
      fields+='<div class="fld"><label>'+lbl+' <span class="mut" style="font-weight:400">(type a new one or pick existing)</span></label>'+
        '<input id="f_'+k+'" list="dl_'+k+'" autocomplete="off" value="'+esc(val)+'" placeholder="Type or choose…">'+
        '<datalist id="dl_'+k+'">'+opts.map(o=>'<option value="'+esc(o)+'"></option>').join('')+'</datalist></div>'; }
    else if(type==='lines:products'){
      /* v4.42 — as many items on one purchase as the invoice has. The four fixed slots this
         replaces meant a five-line delivery had to be entered as two purchases. Rows are posted
         as the same `lines` array the classic Purchases screen sends, so the endpoint is unchanged. */
      window.LINES=window.LINES||{}; window.LINES[k]={map:(a.productMap||{})};
      const _opts=Object.keys(a.productMap||{}).map(o=>'<option value="'+esc(o)+'"></option>').join('');
      fields+='<div class="fld"><label>'+lbl+'</label>'+
        '<table style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr>'+
          '<th style="text-align:left;padding:4px 6px;font-size:11px;color:var(--muted,#6b7280)">Item</th>'+
          '<th style="text-align:left;padding:4px 6px;width:70px;font-size:11px;color:var(--muted,#6b7280)">Qty</th>'+
          '<th style="text-align:left;padding:4px 6px;width:92px;font-size:11px;color:var(--muted,#6b7280)">Cost each</th>'+
          '<th style="text-align:right;padding:4px 6px;width:86px;font-size:11px;color:var(--muted,#6b7280)">Line</th>'+
          '<th style="width:30px"></th></tr></thead><tbody id="lnb_'+k+'"></tbody></table>'+
        '<datalist id="dl_'+k+'">'+_opts+'</datalist>'+
        '<div style="display:flex;align-items:center;gap:10px;margin-top:8px">'+
          '<button type="button" class="btn sm" onclick="lnAdd(\''+k+'\')">+ Add item</button>'+
          '<span id="lntot_'+k+'" style="margin-left:auto;font-weight:700">\u2014</span>'+
        '</div></div>'; }
    else if(type==='textarea'){
      fields+='<div class="fld"><label>'+lbl+'</label><textarea id="f_'+k+'" rows="4" style="width:100%;padding:9px 11px;border:1px solid var(--line,#e4e9e4);border-radius:9px;font:inherit;font-size:14px;resize:vertical">'+esc(val)+'</textarea></div>'; }
    else if(type==='date'){
      fields+='<div class="fld"><label>'+lbl+'</label><input type="date" id="f_'+k+'" value="'+esc(val)+'"></div>'; }
    else if(type==='datetime'){
      fields+='<div class="fld"><label>'+lbl+'</label><input type="datetime-local" id="f_'+k+'" value="'+esc(val)+'"></div>'; }
    else { const t=(type==='money'||type==='number')?'type="number" step="'+(type==='money'?'0.01':'1')+'"':''; const ph=type==='money'?'placeholder="0.00"':'';
      fields+='<div class="fld"><label>'+lbl+'</label><input id="f_'+k+'" '+t+' '+ph+' value="'+esc(val)+'"></div>'; }
  });
  // Optional live WhatsApp preview: fields on the left, a phone frame on the right that
  // composes exactly what the customer will receive as you type.
  const __body = a.waPreview
    ? '<div class="wa-split"><div class="wa-formcol">'+fields+'</div><div class="wa-previewcol">'+waPhoneShell(a)+'</div></div>'
    : fields;
  const __mEl=document.getElementById('modal'); __mEl.style.maxWidth=''; __mEl.classList.toggle('wa-modal', !!a.waPreview);
  __mEl.innerHTML='<div class="h">'+esc(a.label)+'<button class="x" onclick="closeM()"><svg class="ic" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"/></svg></button></div><div class="b">'+__body+'</div><div class="f"><button class="btn" onclick="closeM()">Cancel</button><button class="btn pri" id="mok">'+esc(a.submitLabel||'Save')+'</button></div>';
  document.getElementById('ov').classList.add('on');
  Object.entries(a.schema).forEach(([k,type])=>{ if(type==='gallery') renderGal(k); if(type==='mediapick') mpChips(k); });
  if(a.waPreview) waPreviewInit(a);
  // Auto-fill a rate field from a source select (e.g. currency → rate_to_usd) so the user can't mismatch them.
  if(a.autoRate && a.autoRate.field && a.autoRate.source && a.autoRate.map){
    const src=document.getElementById('f_'+a.autoRate.source), tgt=document.getElementById('f_'+a.autoRate.field);
    if(src&&tgt){ const apply=()=>{ const v=a.autoRate.map[src.value]; if(v!=null) tgt.value=v; }; src.addEventListener('change',apply); apply(); }
  }
  // autoNote: auto-write the description from the other fields (editable; stops auto-updating once the user types).
  if(a.autoNote && a.autoNote.template){
    const nf=document.getElementById('f_note');
    if(nf){
      let touched = !!(a.prefill && a.prefill.note!=null && String(a.prefill.note)!=='');
      const render=()=>a.autoNote.template.replace(/\{(\w+)\}/g,(m,k)=>{ const el=document.getElementById('f_'+k); const v=el?el.value:((a.prefill&&a.prefill[k])||''); return (v==null?'':String(v)); }).replace(/\s+/g,' ').replace(/\s+—\s*$/,'').trim();
      if(!touched){ nf.value=render(); }
      nf.addEventListener('input',()=>{ touched=true; });
      (a.autoNote.watch||[]).forEach(k=>{ const el=document.getElementById('f_'+k); if(el){ ['change','input'].forEach(ev=>el.addEventListener(ev,()=>{ if(!touched) nf.value=render(); })); } });
    }
  }
  // "Improve description with AI": when a form has a product photo GALLERY and a description
  // textarea (i.e. the product editor), offer a one-tap AI draft written from the first photo.
  // Reuses /papi/ai-describe; the draft lands in the field for the seller to review before Save,
  // and name/brand/category are sent as grounding when those fields exist. Gallery-only (not the
  // mediapick used by campaigns) so it never appears on a non-product form.
  (function(){
    try{
      let imgKey=null, descKey=null;
      Object.entries(a.schema||{}).forEach(([k,t])=>{ if(t==='gallery'&&!imgKey)imgKey=k; if(t==='textarea'&&!descKey)descKey=k; });
      if(!imgKey||!descKey) return;
      const ta=document.getElementById('f_'+descKey); if(!ta) return;
      const btn=document.createElement('button');
      btn.type='button'; btn.className='btn sm'; btn.style.marginTop='6px'; btn.textContent='✨ Improve description with AI';
      btn.onclick=async()=>{
        const g=window.GAL&&window.GAL[imgKey]; const img=(g&&g.length)?g[0]:'';
        if(!img){ toast('Add a product photo first',true); return; }
        const fv=k=>{ const el=document.getElementById('f_'+k); return el?(el.value||'').trim():''; };
        const t0=btn.textContent; btn.disabled=true; btn.textContent='Writing…';
        try{
          const r=await post('/papi/ai-describe',{ image:img, name:fv('name'), brand:fv('brand'), category:fv('category'), pack:fv('pack_size') });
          if(r&&r.ok&&r.description){ ta.value=r.description; toast('✨ AI draft — review, then Save'); }
          else if(r&&r.error==='ai_unavailable'){ toast('AI is not set up on the server yet',true); }
          else{ toast('Could not generate a description',true); }
        }catch(e){ toast('Could not generate a description',true); }
        btn.disabled=false; btn.textContent=t0;
      };
      ta.parentNode.appendChild(btn);
    }catch(e){}
  })();
  // Live preview: show a computed result (e.g. amount × rate) that updates as the user types.
  if(a.preview && a.preview.left && a.preview.right){
    const pv=document.createElement('div'); pv.className='fpreview'; pv.id='fpv';
    document.querySelector('#modal .b').appendChild(pv);
    const compute=()=>{ const L=parseFloat((document.getElementById('f_'+a.preview.left)||{}).value)||0; const R=parseFloat((document.getElementById('f_'+a.preview.right)||{}).value)||0;
      const cur=a.preview.curField?((document.getElementById('f_'+a.preview.curField)||{}).value||''):'';
      const val=L*R; pv.innerHTML='<span>'+esc(a.preview.label||'You get')+'</span><strong>'+(val>0?val.toLocaleString(undefined,{maximumFractionDigits:2}):'—')+(cur?' '+esc(cur):'')+'</strong>'; };
    ['left','right','curField'].forEach(k=>{ if(a.preview[k]){ const el=document.getElementById('f_'+a.preview[k]); if(el){ el.addEventListener('input',compute); el.addEventListener('change',compute); } } });
    compute();
  }
  // v4.42 — start a line table with two empty rows; the person adds more as they type.
  Object.entries(a.schema).forEach(([k,type])=>{ if(type==='lines:products'){ lnAdd(k); lnAdd(k); } });

  document.getElementById('mok').onclick=async()=>{ const body=Object.assign({}, a.body||{});
    Object.entries(a.schema).forEach(([k,type])=>{ if(type==='gallery'){ body[k]=window.GAL[k]||[]; }
      else if(type==='mediapick'){ const el=document.getElementById('f_'+k); if(el) body[k]=(el.value||'').trim();
        body.product_ids=((window.MP&&window.MP[k]&&window.MP[k].products)||[]).map(p=>p.id).filter(x=>x!=null); }
      else if(type==='lines:products'){
        const rows=[]; const map=(window.LINES&&window.LINES[k]&&window.LINES[k].map)||{};
        document.querySelectorAll('#lnb_'+k+' tr').forEach(tr=>{
          const nm=((tr.querySelector('.ln-p')||{}).value||'').trim();
          const q=parseFloat((tr.querySelector('.ln-q')||{}).value)||0;
          const u=parseFloat((tr.querySelector('.ln-u')||{}).value)||0;
          if(nm!=='' && q>0) rows.push({product_id:(map[nm]||null), name:nm, qty:q, unit_cost:u});
        });
        body.lines=JSON.stringify(rows); body.__lineCount=rows.length; }
      else { const el=document.getElementById('f_'+k); if(el) body[k]=el.value; } });
    // A purchase with no usable row is a mistake, not an empty purchase — say so and stay open.
    if(body.__lineCount===0){ toast('Add at least one item with a quantity.',true); return; }
    delete body.__lineCount;
    // pathMap: resolve the target URL from a picked field value (e.g. supplier name → /papi/suppliers/{id}/pay).
    let ep=a.endpoint;
    if(a.pathMap && a.pathMap.field && a.pathMap.map){ const sel=body[a.pathMap.field]; const mapped=a.pathMap.map[sel];
      if(!mapped){ toast(a.pathMap.error||'Please choose a valid option',true); return; }
      ep=mapped; delete body[a.pathMap.field]; }
    // valueMap: translate a picked display value into the real value to send (e.g. "SO-12 · Shop 58 · owes …" → order id).
    if(a.valueMap && a.valueMap.field && a.valueMap.map){ const sel=body[a.valueMap.field]; const mapped=a.valueMap.map[sel];
      if(mapped===undefined || mapped===null){ toast(a.valueMap.error||'Please pick a valid option from the list',true); return; }
      const into=a.valueMap.into||a.valueMap.field; body[into]=mapped; if(into!==a.valueMap.field) delete body[a.valueMap.field]; }
    // submitConvert: the user enters the amount in the DISPLAY currency (e.g. USD); multiply by the
    // factor to the record's NATIVE currency (e.g. UGX) before posting, so the endpoint keeps its
    // native-currency contract while the user thinks in the report currency they see everywhere else.
    if(a.submitConvert && a.submitConvert.field && a.submitConvert.factor){
      const f=a.submitConvert.field; const v=parseFloat(body[f]);
      if(!isNaN(v)) body[f]=Math.round(v*a.submitConvert.factor*100)/100;
    }
    // flow:'convert' — one person's cash is converted; if a different receiver is named, the converted
    // amount is then handed to them (convert under source, then transfer), so both people's balances land right.
    if(a.flow==='convert'){
      closeM();
      const fromAmt=parseFloat(body.from_amount)||0, from=body.from_currency, to=body.to_currency, rate=parseFloat(body.rate)||0, src=(body.holder||'').trim(), rcv=(body.receiver||'').trim();
      if(fromAmt<=0||rate<=0||!src){ toast('Enter the amount, rate and whose cash is converted',true); return; }
      const toAmt=Math.round(fromAmt*rate*100)/100;
      const r1=await post('/papi/trade-cashbook/convert',{from_currency:from,to_currency:to,from_amount:fromAmt,rate:rate,holder:src,note:body.note||''});
      if(!r1||!r1.ok){ toast((r1&&(r1.message||r1.error))||'Conversion failed',true); refresh(); return; }
      if(rcv && rcv.toLowerCase()!==src.toLowerCase()){
        const r2=await post('/papi/trade-cashbook/transfer',{from_person:src,to_person:rcv,currency:to,amount:toAmt,note:'Converted '+from+' → '+to+', handed to '+rcv});
        toast((r2&&r2.ok)?(src+' converted '+from+' '+fromAmt.toLocaleString()+' → '+toAmt.toLocaleString()+' '+to+', handed to '+rcv):('Converted, but handing to '+rcv+' failed — use "Moved cash between people"'), !(r2&&r2.ok));
      } else { toast(src+' converted '+from+' '+fromAmt.toLocaleString()+' → '+toAmt.toLocaleString()+' '+to, false); }
      refresh(); return;
    }
    // queryWrite: submit the form as a GET query-string write to a legacy panel endpoint.
    if(a.queryWrite){ closeM(); const res=await getQuery(ep, body); toast(res&&res.ok?(res.message||'Saved'):((res&&(res.message||res.error))||'Error'), !(res&&res.ok)); refresh(); return; }
    closeM(); const res=await post(ep,body);
    // onResult:'payment' — surface the customer's remaining balance right here instead of a generic "Saved".
    let msg=res.message;
    if(a.onResult==='payment' && res.ok){ msg = (res.state==='paid') ? ('✓ '+(res.order_no||'Order')+' paid in full') : ((res.order_no||'Order')+' — balance left: '+Number(res.balance||0).toLocaleString()); }
    toast(res.ok?(msg||'Saved'):(msg||res.error||'Error'), !res.ok);
    // goto: a create action can ask us to open the record it just made (e.g. a new LPO from the
    // supplier screen). Backward-compatible — only fires when the server sends it.
    if(res.ok && res.goto){ const h=apiToHash(res.goto); if(h){ route(h.key,h.id); return; } }
    refresh(); };
}
function renderGal(k){
  const el=document.getElementById('gal_'+k); if(!el) return; const urls=window.GAL[k]||[];
  let h=urls.map((u,i)=>'<div class="gthumb" style="background-image:url(\''+esc(u)+'\')">'+(i===0?'<span class="gmain">Main</span>':'')+'<button type="button" class="grm" onclick="galRemove(\''+k+'\','+i+')">×</button></div>').join('');
  if(urls.length<4) h+='<div class="gadd" onclick="document.getElementById(\'galf_'+k+'\').click()"><span>＋</span><span class="gaddt">Add photo</span></div>';
  el.innerHTML=h;
}
function galRemove(k,i){ (window.GAL[k]||[]).splice(i,1); renderGal(k); }
async function galUpload(k){
  const fi=document.getElementById('galf_'+k); const f=fi&&fi.files&&fi.files[0]; if(!f) return; fi.value='';
  if(f.size>6*1024*1024){ toast('Image too large (max 6MB)', true); return; }
  const el=document.getElementById('gal_'+k); if(el) el.insertAdjacentHTML('beforeend','<div class="gthumb gload" id="galload_'+k+'">…</div>');
  const reader=new FileReader();
  reader.onload=async()=>{ try{ const res=await post('/api/v1/workspace/products/_/upload_image',{data:reader.result,name:f.name});
    if(res.ok&&res.url){ (window.GAL[k]=window.GAL[k]||[]).push(res.url); } else { toast(res.message||'Upload failed', true); } }
    catch(e){ toast('Upload failed', true); } renderGal(k); };
  reader.readAsDataURL(f);
}
async function uploadPhoto(k){
  const fi=document.getElementById('file_'+k); const f=fi&&fi.files&&fi.files[0]; if(!f) return;
  const st=document.getElementById('up_'+k); if(st) st.textContent='Uploading…';
  if(f.size>6*1024*1024){ if(st) st.textContent='Image too large (max 6MB)'; return; }
  const reader=new FileReader();
  reader.onload=async()=>{
    try{
      const res=await post('/api/v1/workspace/products/_/upload_image',{data:reader.result,name:f.name});
      if(res.ok&&res.url){ document.getElementById('f_'+k).value=res.url; const pv=document.getElementById('prev_'+k); if(pv) pv.style.backgroundImage='url(\''+res.url+'\')'; if(st) st.textContent='✓ uploaded'; }
      else { if(st) st.textContent=res.message||res.error||'Upload failed'; }
    }catch(e){ if(st) st.textContent='Upload failed'; }
  };
  reader.readAsDataURL(f);
}
/* ===== mediapick: product-photo picker + upload + URL, for campaigns & the like ===== */
var MPT={};
function cssBg(u){ u=(u==null?'':String(u)).trim(); return u?("background-image:url('"+u.replace(/'/g,'%27').replace(/"/g,'%22')+"')"):''; }
function mpSync(k){ // paste-a-URL / after upload: reflect the current URL into MP + preview
  var el=document.getElementById('f_'+k); if(!el) return; var u=(el.value||'').trim();
  if(window.MP&&window.MP[k]) window.MP[k].url=u;
  var pv=document.getElementById('prev_'+k); if(pv) pv.style.cssText=cssBg(u);
  if(window.__WAPV&&window.__WAPV._active) waPreviewUpdate();
}
function mpSearch(k){
  var box=document.getElementById('mpres_'+k), inp=document.getElementById('mpq_'+k); if(!box||!inp) return;
  var q=inp.value.trim(); clearTimeout(MPT[k]);
  if(q.length<2){ box.innerHTML=''; box.classList.remove('on'); return; }
  var ep=(window.MP[k]&&window.MP[k].searchEp)||'/api/v1/workspace/products/_/search';
  MPT[k]=setTimeout(async function(){
    var res=await post(ep,{q:q}); var items=(res&&res.items)||[];
    window.MP[k]._res=items;
    box.innerHTML=items.length? items.map(function(it,i){
        var th=it.image?('<span class="mp-th" style="'+cssBg(it.image)+'"></span>'):'<span class="mp-th mp-th0">'+icon('products','ic-sm')+'</span>';
        return '<div class="qb-opt" onclick="mpPick(\''+k+'\','+i+')">'+th+'<div style="flex:1"><div class="qb-on">'+esc(it.name)+'</div><div class="qb-op">'+esc(curFmt(it.price,'UGX'))+(it.unit?' / '+esc(it.unit):'')+(it.image?'':' · no photo')+'</div></div></div>';
      }).join('')
      : '<div class="qb-empty" style="padding:9px 12px;color:var(--muted);font-size:12.5px">No products match “'+esc(q)+'”</div>';
    box.classList.add('on');
  },220);
}
function mpPick(k,i){
  var it=((window.MP[k]&&window.MP[k]._res)||[])[i]; if(!it) return;
  var box=document.getElementById('mpres_'+k), inp=document.getElementById('mpq_'+k); if(box){box.innerHTML='';box.classList.remove('on');} if(inp) inp.value='';
  // attach the product (name/price line rides along via product_ids); dedupe by id then name
  var list=window.MP[k].products=window.MP[k].products||[];
  if(!list.some(function(p){return (it.id!=null&&p.id===it.id)||(p.name||'')===it.name;})) list.push({id:it.id,name:it.name,price:it.price,image:it.image||''});
  // use its photo as the campaign image (a real product photo is what you want to send)
  if(it.image){ var el=document.getElementById('f_'+k); if(el){ el.value=it.image; } mpSync(k); }
  mpChips(k);
}
function mpUnpick(k,idx){ var l=(window.MP[k]&&window.MP[k].products)||[]; l.splice(idx,1); mpChips(k); }
function mpChips(k){
  var el=document.getElementById('mpchips_'+k); if(!el) return; var l=(window.MP[k]&&window.MP[k].products)||[];
  if(!l.length){ el.innerHTML=''; return; }
  el.innerHTML='<div class="mp-chips-h">Attached products (their name &amp; price are added to the message):</div>'+
    l.map(function(p,i){ return '<span class="mp-chip">'+(p.image?'<span class="mp-chdot" style="'+cssBg(p.image)+'"></span>':'')+esc(p.name)+'<button type="button" onclick="mpUnpick(\''+k+'\','+i+')">×</button></span>'; }).join('');
  if(window.__WAPV&&window.__WAPV._active) waPreviewUpdate();
}
/* mediapick upload — uses the field's configured endpoint (campaigns' own, under menu:customers). */
async function mpUpload(k){
  var fi=document.getElementById('file_'+k); var f=fi&&fi.files&&fi.files[0]; if(!f) return;
  var st=document.getElementById('up_'+k); if(st) st.textContent='Uploading…';
  if(f.size>6*1024*1024){ if(st) st.textContent='Image too large (max 6MB)'; return; }
  var ep=(window.MP[k]&&window.MP[k].uploadEp)||'/api/v1/workspace/products/_/upload_image';
  var reader=new FileReader();
  reader.onload=async function(){
    try{ var res=await post(ep,{data:reader.result,name:f.name});
      if(res&&res.ok&&res.url){ var el=document.getElementById('f_'+k); if(el) el.value=res.url; mpSync(k); if(st) st.textContent='✓ uploaded'; }
      else { if(st) st.textContent=(res&&(res.message||res.error))||'Upload failed'; }
    }catch(e){ if(st) st.textContent='Upload failed'; }
  };
  reader.readAsDataURL(f);
}
/* Preset "send per day" picker — sets the hidden value and (on the campaign form, which has an
   audience field) shows a live "~N days at X/day" plan from the same endpoint the classic screen uses. */
async function pdPick(k,v){
  var h=document.getElementById('f_'+k); if(h) h.value=String(v);
  var box=document.getElementById('pd_'+k);
  if(box){ var bs=box.querySelectorAll('.set-seg'); for(var i=0;i<bs.length;i++){ bs[i].classList.toggle('on', bs[i].getAttribute('data-v')===String(v)); } }
  var plan=document.getElementById('pdplan_'+k); if(!plan) return;
  var audEl=document.getElementById('f_audience'); var perDay=parseInt(v,10)||0;
  if(!audEl){ plan.textContent=''; return; }
  if(perDay<=0){ plan.textContent='Sends the whole list at once.'; return; }
  var catEl=document.getElementById('f_category');
  plan.textContent='Counting…';
  try{
    var d=await post('/papi/campaign/audience',{audience:audEl.value,category:catEl?catEl.value:null,daily_send_cap:perDay});
    if(d&&d.ok&&d.days){ var bd=(d.breakdown||[]); var shown=bd.slice(0,7).join(' + ')+(bd.length>7?(' + … ('+(bd.length-7)+' more days)'):'');
      plan.innerHTML='📅 ~<b>'+d.days+' day'+(d.days===1?'':'s')+'</b> at '+perDay+'/day — '+esc(shown)+' · '+(d.count||0)+' customers'; }
    else { plan.textContent=''; }
  }catch(e){ plan.textContent=''; }
}
/* ===== Live WhatsApp preview (mobile frame) for the campaign form ===== */
var __WAPV=null;
function waPhoneShell(a){
  var cfg=a.waPreview||{}; var biz=String(cfg.business||'Your business');
  var ini=(biz.trim().split(/\s+/).map(function(w){return w[0];}).join('').slice(0,2)||'B').toUpperCase();
  return '<div class="wa-phone"><div class="wa-notch"></div>'+
    '<div class="wa-top">'+
      '<svg class="wa-bk" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/></svg>'+
      '<div class="wa-av">'+esc(ini)+'</div>'+
      '<div class="wa-ti"><div class="wa-nm">'+esc(biz)+'</div><div class="wa-st">business account</div></div>'+
      '<svg class="wa-ic2" viewBox="0 0 24 24"><path d="M23 7l-7 5 7 5V7zM1 5h15v14H1z"/></svg>'+
      '<svg class="wa-ic2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>'+
    '</div>'+
    '<div class="wa-chat"><div class="wa-daypill">TODAY</div><div class="wa-bubble" id="waBubble"></div></div>'+
    '<div class="wa-inbar"><div class="wa-inbox">Message</div><div class="wa-send">'+icon('send','ic-sm')+'</div></div>'+
  '</div><div class="wa-hint">Live preview — exactly what the customer receives</div>';
}
function waPreviewInit(a){
  __WAPV=a.waPreview||{}; __WAPV._active=true;
  ['messageField','ctaField','imageField'].forEach(function(fk){
    var k=__WAPV[fk]; if(!k) return; var el=document.getElementById('f_'+k);
    if(el){ ['input','change'].forEach(function(ev){ el.addEventListener(ev,waPreviewUpdate); }); }
  });
  waPreviewUpdate();
}
/* Mirrors SendCampaign::compose() on the server so the preview == what actually sends. */
function waPreviewCompose(){
  if(!__WAPV) return {text:'',image:''};
  var g=function(fk){ var k=__WAPV[fk]; if(!k) return null; return document.getElementById('f_'+k); };
  var msgEl=g('messageField'), ctaEl=g('ctaField'), imgEl=g('imageField');
  var message=msgEl?String(msgEl.value||'').trim():'';
  var cta=ctaEl?String(ctaEl.value||'').trim():'';
  var image=imgEl?String(imgEl.value||'').trim():'';
  var prods=[]; var mk=__WAPV.imageField;
  if(mk && window.MP && window.MP[mk]) prods=window.MP[mk].products||[];
  var cur=__WAPV.currency||'UGX'; var dec=(__WAPV.dec!=null?__WAPV.dec:0);
  var lines=[];
  if(message) lines.push(message);
  prods.forEach(function(p){ lines.push('• '+p.name+' — '+cur+' '+Number(p.price||0).toLocaleString('en-US',{minimumFractionDigits:dec,maximumFractionDigits:dec})); });
  if(cta){ lines.push(''); lines.push(cta); }
  return {text:lines.join('\n'), image:image};
}
function waPreviewUpdate(){
  var el=document.getElementById('waBubble'); if(!el||!__WAPV) return;
  var c=waPreviewCompose();
  var now=new Date(); var hh=now.getHours(), mm=String(now.getMinutes()).padStart(2,'0');
  var ap=hh<12?'AM':'PM'; var h12=((hh+11)%12)+1; var time=h12+':'+mm+' '+ap;
  var html='';
  if(c.image) html+='<div class="wa-img" style="'+cssBg(c.image)+'"></div>';
  var txt=(c.text||'').trim();
  if(txt){ html+='<div class="wa-txt">'+esc(txt).replace(/\n/g,'<br>')+'</div>'; }
  else if(!c.image){ html+='<div class="wa-txt wa-ph">Your message preview will appear here…</div>'; }
  html+='<div class="wa-time">'+esc(time)+'</div>';
  el.innerHTML=html;
  el.classList.toggle('wa-hasimg', !!c.image);
}
/* Generic file field (zip/csv/etc) — reads the chosen file to a base64 data URL held in the hidden f_<k>. */
function uploadFileField(k){
  const fi=document.getElementById('upl_'+k); const f=fi&&fi.files&&fi.files[0]; if(!f) return;
  const st=document.getElementById('upls_'+k);
  if(f.size>25*1024*1024){ if(st) st.textContent='File too large (max 25MB)'; document.getElementById('f_'+k).value=''; return; }
  if(st) st.textContent='Reading '+f.name+'…';
  const reader=new FileReader();
  reader.onload=()=>{ document.getElementById('f_'+k).value=String(reader.result||''); if(st) st.textContent='✓ '+f.name+' ready — click Save to import'; };
  reader.onerror=()=>{ if(st) st.textContent='Could not read the file'; document.getElementById('f_'+k).value=''; };
  reader.readAsDataURL(f);
}
function closeM(){ document.getElementById('ov').classList.remove('on'); __WAPV=null; var m=document.getElementById('modal'); if(m) m.classList.remove('wa-modal'); }
document.getElementById('ov').addEventListener('click',e=>{ if(e.target.id==='ov') closeM(); });
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeM(); });
// The header search box is now the entry point to the ⌘K command center — focusing it (tap or click)
// opens the palette. The old "customer 5" quick-jump is kept as a keydown fallback for the rare case
// the box is typed into directly (e.g. before scripts wire up).
(function(){ const s=document.getElementById('search'); if(!s) return;
  s.setAttribute('placeholder','Search…  ⌘K');
  s.addEventListener('focus',()=>{ openCmdk(); s.blur(); });
  s.addEventListener('keydown',e=>{ if(e.key==='Enter'){ const p=e.target.value.trim().split(/\s+/); if(p[0]&&p[1]) route(p[0].toLowerCase(),p[1]); } });
})();
window.addEventListener('hashchange',()=>{ const h=parseHash(); if(h.key && (h.key!==CUR.key||h.id!==CUR.id)) route(h.key,h.id); });

/* ============================================================================
   ⌘K COMMAND CENTER (Phase B) — one palette for: find a record · jump to a screen · run an action.
   Additive & read-only against existing endpoints:
     • records come from each list capability's `search` command (/api/v1/workspace/<list>/_/search)
       — the SAME command the New-invoice customer/product pickers already use; no new query logic.
     • "Go to" targets are read straight off the rendered sidebar, so the palette can only ever offer
       screens the role already has (permissions stay the single gate).
     • Quick actions route to the existing create flows — no new create logic here.
   Entities are gated on CFG.capKeys so a role never queries a workspace it cannot open.
   ========================================================================== */
const CMD_ENTITIES = [
  { list:'customers',   obj:'customer',   label:'Customers', icon:'user' },
  { list:'salesOrders', obj:'salesOrder', label:'Orders',    icon:'cart' },
  { list:'invoices',    obj:'invoice',    label:'Invoices',  icon:'receipt' },
  { list:'products',    obj:'product',    label:'Products',  icon:'box' },
  { list:'suppliers',   obj:'supplier',   label:'Suppliers', icon:'truck' },
];
// Quick actions hand off to flows that already exist; the palette adds no create logic of its own.
const CMD_ACTIONS = [
  { label:'New sale',       hint:'Open the POS till',    icon:'cart',    gate:()=>capHas('pos'),             run:()=>route('pos') },
  { label:'Add customer',   hint:'Create a customer',    icon:'user',    gate:()=>capHas('customers'),       run:()=>quickAdd('customers') },
  { label:'New invoice',    hint:'Raise an invoice',     icon:'receipt', gate:()=>capHas('invoices'),        run:()=>quickAdd('invoices') },
  { label:'Record payment', hint:'Open Payments',        icon:'dollar',  gate:()=>capHas('payments'),        run:()=>route('payments') },
  { label:'Receive stock',  hint:'Open Purchasing',      icon:'truck',   gate:()=>capHas('purchase.process'),run:()=>route('purchase.process') },
];
function capHas(k){ return !!(CFG.capKeys && CFG.capKeys.has(k)); }
// Jump targets = whatever the sidebar actually rendered for this role (permission-accurate by construction).
function cmdkNavTargets(){
  return Array.from(document.querySelectorAll('#nav .nitem')).map(n=>({
    key: n.dataset.key,
    label: (n.querySelector('.nl') ? n.querySelector('.nl').textContent : n.dataset.key) || n.dataset.key,
  }));
}
let PAL = { open:false, seq:0, sel:-1, rows:[], q:'', loading:false, last:[] };
function ensureCmdk(){
  let ov = document.getElementById('cmdk'); if(ov) return ov;
  ov = document.createElement('div'); ov.id='cmdk'; ov.className='cmdk-ov';
  ov.innerHTML = '<div class="cmdk" role="dialog" aria-label="Command center" aria-modal="true">'
    + '<div class="cmdk-h"><svg class="ic" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>'
    + '<input id="cmdkInput" placeholder="Search records, jump to a screen, or run an action…" autocomplete="off" spellcheck="false" aria-label="Search">'
    + '<span class="cmdk-esc">esc</span></div>'
    + '<div class="cmdk-body" id="cmdkBody"></div>'
    + '<div class="cmdk-foot"><span><b>↑↓</b> move</span><span><b>↵</b> open</span><span><b>esc</b> close</span></div>'
    + '</div>';
  ov.addEventListener('click', e=>{ if(e.target===ov) closeCmdk(); });
  document.body.appendChild(ov);
  const inp = ov.querySelector('#cmdkInput');
  inp.addEventListener('input', e=>palInput(e.target.value));
  inp.addEventListener('keydown', palKey);
  return ov;
}
function openCmdk(seed){
  const ov = ensureCmdk(); ov.classList.add('on'); PAL.open=true;
  const inp = document.getElementById('cmdkInput');
  inp.value = seed || ''; palInput(inp.value);
  setTimeout(()=>{ inp.focus(); inp.select(); }, 20);
}
function closeCmdk(){ const ov=document.getElementById('cmdk'); if(ov) ov.classList.remove('on'); PAL.open=false; }
function palInput(v){
  PAL.q = v; const q = v.trim();
  clearTimeout(PAL._t);
  if(q.length < 2){ PAL.loading=false; PAL.last=[]; palRender(q, []); return; }
  PAL.loading = true; palRender(q, PAL.last);          // instant: actions + nav, with a "searching" spinner
  PAL._t = setTimeout(()=>palSearch(q), 170);
}
async function palSearch(q){
  const seq = ++PAL.seq;
  const ents = CMD_ENTITIES.filter(e=>capHas(e.list));
  const results = await Promise.all(ents.map(async e=>{
    try{ const r = await post('/api/v1/workspace/'+e.list+'/_/search', { q }); return { e, items: (r && r.ok && Array.isArray(r.items)) ? r.items : [] }; }
    catch(_){ return { e, items: [] }; }
  }));
  if(seq !== PAL.seq) return;                          // a newer keystroke already superseded this query
  PAL.loading = false; PAL.last = results; palRender(q, results);
}
function palSection(t, busy){ return '<div class="cmdk-sec">'+esc(t)+(busy?'<span class="cmdk-busy"></span>':'')+'</div>'; }
function palRow(i, ic, title, sub, tag){
  return '<button class="cmdk-row" data-i="'+i+'" onmouseenter="PAL.sel='+i+';palHighlight()" onclick="palActivate('+i+')">'
    + '<span class="cmdk-ic">'+icon(ic)+'</span>'
    + '<span class="cmdk-txt"><span class="cmdk-t">'+esc(title)+'</span>'+(sub?'<span class="cmdk-s">'+esc(sub)+'</span>':'')+'</span>'
    + (tag?'<span class="cmdk-tag">'+esc(tag)+'</span>':'') + '</button>';
}
function palRender(q, results){
  const ql = q.toLowerCase();
  const rows = []; let html = '';
  const acts = CMD_ACTIONS.filter(a=>a.gate() && (!ql || a.label.toLowerCase().indexOf(ql)>=0 || a.hint.toLowerCase().indexOf(ql)>=0));
  if(acts.length){
    html += palSection('Quick actions');
    acts.forEach(a=>{ const i=rows.length; rows.push({ kind:'action', run:a.run }); html += palRow(i, a.icon, a.label, a.hint, 'Action'); });
  }
  if(ql){
    const navs = cmdkNavTargets().filter(n=>String(n.label).toLowerCase().indexOf(ql)>=0).slice(0,6);
    if(navs.length){
      html += palSection('Go to');
      navs.forEach(n=>{ const i=rows.length; rows.push({ kind:'nav', key:n.key }); html += palRow(i, 'file', n.label, '', 'Screen'); });
    }
  }
  (results||[]).forEach(g=>{
    if(!g.items.length) return;
    html += palSection(g.e.label);
    g.items.slice(0,6).forEach(it=>{ const i=rows.length; rows.push({ kind:'record', obj:g.e.obj, id:it.id }); html += palRow(i, g.e.icon, it.name, it.sub||'', ''); });
  });
  if(PAL.loading && ql.length>=2){ html += palSection('Searching…', true); }
  if(!rows.length && !PAL.loading){
    html = ql.length>=2
      ? '<div class="cmdk-empty">No matches for “'+esc(q)+'”.<br>Try a name, number, or phone.</div>'
      : '<div class="cmdk-empty">Search customers, orders, invoices, products and suppliers —<br>or jump to any screen. Start typing.</div>';
  }
  PAL.rows = rows; PAL.sel = rows.length ? 0 : -1;
  const body = document.getElementById('cmdkBody'); if(body){ body.innerHTML = html; palHighlight(); }
}
function palHighlight(){
  const body = document.getElementById('cmdkBody'); if(!body) return;
  const els = body.querySelectorAll('.cmdk-row');
  els.forEach((el,i)=>{ const on = i===PAL.sel; el.classList.toggle('on', on); if(on) el.scrollIntoView({ block:'nearest' }); });
}
function palMove(d){ if(!PAL.rows.length) return; PAL.sel = (PAL.sel + d + PAL.rows.length) % PAL.rows.length; palHighlight(); }
function palActivate(i){
  const r = PAL.rows[i]; if(!r) return; closeCmdk();
  if(r.kind==='record') route(r.obj, r.id);
  else if(r.kind==='nav') route(r.key);
  else if(r.kind==='action' && typeof r.run==='function') r.run();
}
function palKey(e){
  if(e.key==='ArrowDown'){ e.preventDefault(); palMove(1); }
  else if(e.key==='ArrowUp'){ e.preventDefault(); palMove(-1); }
  else if(e.key==='Enter'){ e.preventDefault(); if(PAL.sel>=0) palActivate(PAL.sel); }
  else if(e.key==='Escape'){ e.preventDefault(); closeCmdk(); }
}
// ⌘K / Ctrl+K toggles the palette anywhere; Escape closes it when open.
document.addEventListener('keydown', e=>{
  if((e.metaKey||e.ctrlKey) && (e.key==='k' || e.key==='K')){ e.preventDefault(); PAL.open ? closeCmdk() : openCmdk(); return; }
  if(e.key==='Escape' && PAL.open){ closeCmdk(); }
});

/* ============ quotation builder (multi-line) ============ */
let QBT=null;
function quoteBuilder(a){
  const cur=(a.prefill&&a.prefill.currency)||'UGX';
  const edit=!!a.edit;
  const invoice=!!a.invoice;
  // Edit mode pre-loads the quotation's existing lines so they can be changed/removed.
  const seed=(a.lines||[]).map(l=>({name:String(l.name||''),unit:String(l.unit||l.unit_label||''),qty:Math.max(1,parseInt(l.qty||1,10)||1),price:Math.max(0,parseFloat(l.price!=null?l.price:l.unit_price)||0)}));
  window.QB={lines:seed, cur:cur, a:a, edit:edit, invoice:invoice, _res:[], _cres:[], customerId:(a.prefill&&a.prefill.id)||null, customerName:a.customer||'', customerPhone:(a.prefill&&a.prefill.phone)||'', pick:!!a.pickCustomer};
  const curOpts=(a.currencies||[cur]).map(c=>'<option'+(c===cur?' selected':'')+'>'+esc(c)+'</option>').join('');
  const m=document.getElementById('modal'); m.classList.remove('wa-modal'); m.style.maxWidth='660px'; __WAPV=null;
  const custBlock=a.pickCustomer?'<div class="qb-custwrap" id="qbcustwrap"></div>':'';
  // v4.45 — when the lines were copied from another customer's quotation, say so. The prices in
  // them were agreed with somebody else; carrying them across is deliberate and visible, and every
  // line is editable right here, but nobody should have to remember whose discount this was.
  const srcNote=a.sourceNote?'<div class="qb-src" style="display:flex;gap:8px;align-items:flex-start;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:10px;padding:9px 11px;font-size:12.5px;line-height:1.45;margin-bottom:10px"><span>⚠️</span><span>'+esc(a.sourceNote)+'</span></div>':'';
  const noun=invoice?'invoice':'quotation';
  const title=(edit?'Edit ':'New ')+noun+(a.customer?' — '+esc(a.customer):'');
  const okLabel=edit?'Save changes':('Create '+noun);
  const waBlock=(edit||invoice)?'':'<label class="qb-wa"><input type="checkbox" id="qbwa"> Also send the PDF to the customer on WhatsApp</label>';
  m.innerHTML='<div class="h">'+title+'<button class="x" onclick="closeQB()">'+icon('x')+'</button></div>'+
    '<div class="b">'+srcNote+custBlock+
      '<div class="qb-pick"><input id="qbq" placeholder="Search products to add…" autocomplete="off" oninput="qbSearch()"><div class="qb-res" id="qbres"></div></div>'+
      '<div id="qblines"></div>'+
      (invoice?'<div class="qb-adj"><label>Discount <input id="qbdisc" type="number" min="0" step="0.01" value="0" oninput="qbTotal()"></label>'+
        '<label>Delivery / freight <input id="qbfrt" type="number" min="0" step="0.01" value="0" oninput="qbTotal()"></label></div>':'')+
      '<div class="qb-bar"><label class="qb-cur">Currency <select id="qbcur" onchange="qbCur()">'+curOpts+'</select></label>'+
        '<div class="qb-tot">Total <b id="qbtot">'+esc(curFmt(0,cur))+'</b></div></div>'+
      waBlock+
    '</div>'+
    '<div class="f"><button class="btn" onclick="closeQB()">Cancel</button><button class="btn pri" id="qbok" onclick="qbSubmit()"'+(seed.length?'':' disabled')+'>'+esc(okLabel)+'</button></div>';
  document.getElementById('ov').classList.add('on'); if(a.pickCustomer) qbRenderCust(); qbRender();
  setTimeout(()=>{ const f=document.getElementById(a.pickCustomer?'qbcq':'qbq'); if(f) f.focus(); },40);
}
function qbRenderCust(){
  const el=document.getElementById('qbcustwrap'); if(!el) return;
  if(window.QB.customerId){
    el.innerHTML='<div class="qb-chip">'+icon('user','ic-sm')+'<b>'+esc(window.QB.customerName)+'</b><button class="qb-chipx" onclick="qbCustClear()">Change</button></div>';
  } else {
    el.innerHTML='<div class="qb-pick"><input id="qbcq" placeholder="Search a customer…" autocomplete="off" oninput="qbCustSearch()"><div class="qb-res" id="qbcres"></div></div>';
  }
}
function qbCustClear(){ window.QB.customerId=null; window.QB.customerName=''; qbRenderCust(); qbTotal(); setTimeout(()=>{const f=document.getElementById('qbcq');if(f)f.focus();},30); }
function qbCustSearch(){
  const q=document.getElementById('qbcq').value.trim(); const box=document.getElementById('qbcres'); clearTimeout(QBT);
  if(q.length<2){ box.innerHTML=''; box.classList.remove('on'); return; }
  QBT=setTimeout(async()=>{
    const res=await post(window.QB.a.customerSearch,{q}); const items=(res&&res.items)||[]; window.QB._cres=items;
    box.innerHTML=items.length? items.map((it,i)=>'<div class="qb-opt" onclick="qbCustPick('+i+')"><div class="qb-on">'+esc(it.name)+'</div><div class="qb-op">'+esc(it.sub||'')+'</div></div>').join('')
      : '<div class="qb-empty">No customer matches “'+esc(q)+'”</div>';
    box.classList.add('on');
  },220);
}
function qbCustPick(i){ const it=(window.QB._cres||[])[i]; if(!it) return; window.QB.customerId=it.id; window.QB.customerName=it.name; window.QB.customerPhone=it.phone||''; qbRenderCust(); qbTotal(); }
function closeQB(){ const m=document.getElementById('modal'); if(m) m.style.maxWidth=''; closeM(); }
function qbSearch(){
  const q=document.getElementById('qbq').value.trim(); const box=document.getElementById('qbres'); clearTimeout(QBT);
  if(q.length<2){ box.innerHTML=''; box.classList.remove('on'); return; }
  QBT=setTimeout(async()=>{
    const res=await post(window.QB.a.productSearch,{q}); const items=(res&&res.items)||[];
    window.QB._res=items;
    box.innerHTML=items.length? items.map((it,i)=>'<div class="qb-opt" onclick="qbAdd('+i+')"><div class="qb-on">'+esc(it.name)+'</div><div class="qb-op">'+esc(curFmt(it.price,window.QB.cur))+(it.unit?' / '+esc(it.unit):'')+'</div></div>').join('')
      : '<div class="qb-empty">No products match “'+esc(q)+'”</div>';
    box.classList.add('on');
  },220);
}
function qbAdd(i){ const it=(window.QB._res||[])[i]; if(!it) return;
  window.QB.lines.push({name:it.name, unit:it.unit||'', qty:1, price:Number(it.price)||0});
  document.getElementById('qbq').value=''; const box=document.getElementById('qbres'); box.innerHTML=''; box.classList.remove('on'); qbRender();
}
function qbDel(i){ window.QB.lines.splice(i,1); qbRender(); }
function qbEdit(i,f,v){ const l=window.QB.lines[i]; if(!l) return; l[f]= f==='qty'?Math.max(1,parseInt(v||'1',10)||1):Math.max(0,parseFloat(v||'0')||0); qbTotal(); }
function qbCur(){ window.QB.cur=document.getElementById('qbcur').value; qbRender(); }
function qbRender(){
  const el=document.getElementById('qblines'); const L=window.QB.lines;
  if(!L.length){ el.innerHTML='<div class="qb-none">'+icon('cart','ic-lg')+'<div>No products yet — search above to add lines.</div></div>'; }
  else{ el.innerHTML='<div class="qb-head"><span>Product</span><span>Qty</span><span>Unit price</span><span>Amount</span><span></span></div>'+
    L.map((l,i)=>'<div class="qb-row"><div class="qb-nm">'+esc(l.name)+(l.unit?'<span class="qb-u">'+esc(l.unit)+'</span>':'')+'</div>'+
      '<input class="qb-qty" type="number" min="1" value="'+l.qty+'" oninput="qbEdit('+i+',&#39;qty&#39;,this.value)">'+
      '<input class="qb-price" type="number" min="0" step="0.01" value="'+l.price+'" oninput="qbEdit('+i+',&#39;price&#39;,this.value)">'+
      '<div class="qb-amt" id="qbamt'+i+'">'+esc(curFmt(l.qty*l.price,window.QB.cur))+'</div>'+
      '<button class="qb-x" onclick="qbDel('+i+')">'+icon('x','ic-sm')+'</button></div>').join(''); }
  qbTotal();
}
function qbNum(id){ const el=document.getElementById(id); return el?(Math.max(0,parseFloat(el.value||'0')||0)):0; }
function qbTotal(){ let t=0; window.QB.lines.forEach((l,i)=>{ const a=l.qty*l.price; t+=a; const el=document.getElementById('qbamt'+i); if(el) el.textContent=curFmt(a,window.QB.cur); });
  let grand=t;
  if(window.QB.invoice){ grand=Math.max(0, t - qbNum('qbdisc') + qbNum('qbfrt')); }
  const tot=document.getElementById('qbtot'); if(tot) tot.textContent=curFmt(grand,window.QB.cur);
  const ok=document.getElementById('qbok'); if(ok) ok.disabled=window.QB.lines.length===0 || (window.QB.pick && !window.QB.customerId);
}
async function qbSubmit(){
  const a=window.QB.a; const ok=document.getElementById('qbok'); if(!window.QB.lines.length) return;
  if(window.QB.pick && !window.QB.customerId){ toast('Choose a customer first', true); return; }
  if(window.QB.invoice){
    // Issue a numbered invoice via the panel's proven /papi/invoice-issue (customer + line items).
    const body={ customer:window.QB.customerName||'', phone:window.QB.customerPhone||'', currency:window.QB.cur,
      discount:qbNum('qbdisc'), delivery:qbNum('qbfrt'),
      lines:JSON.stringify(window.QB.lines.map(l=>({name:l.name,qty:l.qty,unit:l.price,uom:l.unit||''}))) };
    ok.disabled=true; ok.textContent='Issuing…';
    const res=await post(a.endpoint,body); closeQB();
    if(res&&res.ok){ toast('Invoice '+(res.invoice_no||'')+' created'+(res.already?' (already existed)':''));
      if(res.pdf){ try{ window.open(res.pdf,'_blank'); }catch(e){} } refresh(); }
    else { toast((res&&(res.message||res.error))||'Could not create the invoice', true); }
    return;
  }
  if(window.QB.edit){
    // Edit → reuse the panel's proven quotation-update endpoint (rebuilds the PDF, keeps the number).
    const pf=a.prefill||{};
    const body={ id:pf.id, currency:window.QB.cur, name:a.customer||pf.name||'', phone:pf.phone||'',
      items:JSON.stringify(window.QB.lines.map(l=>({name:l.name,qty:l.qty,unit_price:l.price,unit_label:l.unit||''}))) };
    ok.disabled=true; ok.textContent='Saving…';
    const res=await post(a.endpoint,body); closeQB();
    if(res&&res.ok){ toast(res.message||'Quotation updated'); refresh(); }
    else { toast((res&&(res.message||res.error))||'Could not update quotation', true); }
    return;
  }
  const body={lines:window.QB.lines.map(l=>({name:l.name,qty:l.qty,price:l.price})), currency:window.QB.cur, send_whatsapp:document.getElementById('qbwa').checked};
  if(window.QB.customerId) body.customer_id=window.QB.customerId;
  ok.disabled=true; ok.textContent='Creating…';
  const res=await post(a.endpoint,body); closeQB();
  if(res&&res.ok){ toast(res.message||('Quotation '+res.quote_no+' created'));
    if(res.id){ try{ window.open('/papi/quotation-pdf?id='+res.id,'_blank'); }catch(e){} }
    else if(res.pdf_b64){ downloadB64(res.pdf_b64,res.filename||'Quotation.pdf'); }
    refresh(); }
  else { toast((res&&(res.message||res.error))||'Could not create quotation', true); }
}

/* ================= LIST / DIRECTORY WORKSPACE ================= */
let TBL = { cols:[], rows:[], filters:[], fval:{}, sort:null, dir:1, q:'', dateKey:null, dateLabel:'Created', totals:[], from:'', to:'', page:1, pageSize:15, cur:'UGX' };
function cellVal(c){ return (c && typeof c==='object') ? (c.value!=null?c.value:'') : (c!=null?c:''); }
function cellSort(c){ if(c && typeof c==='object') return c.sort!=null?c.sort:(c.value!=null?c.value:''); return c!=null?c:''; }
function renderList(p){
  const o=p.object||{}; const cap=(p.meta&&p.meta.capability)||CUR.key; const tb=p.table||{};
  window._VIEWMODE = window._VIEWMODE || {};   // per-capability List/Agenda choice, survives refresh()
  TBL = { cols:tb.columns||[], rows:tb.rows||[], filters:tb.filters||[], fval:{}, sort:null, dir:1, q:'',
    dateKey:tb.dateKey||null, dateLabel:tb.dateLabel||'Created', totals:tb.totals||[], from:'', to:'', page:1,
    pageSize:tb.pageSize||15, cur:tb.currency||'UGX', empty:tb.empty||'', capKey:cap,
    mode:(tb.dateKey && window._VIEWMODE[cap])||'table',
    selectable:!!tb.selectable, bulk:tb.bulk||[], sel:{} };
  // A drill-through from another screen (e.g. a category card) pre-applies its filter here.
  if(PENDING_FILTER){ TBL.fval[PENDING_FILTER.key]=PENDING_FILTER.val; PENDING_FILTER=null; }
  let h='<div class="crumb">'+(LABELS[cap]||cap)+'</div>';
  h+='<div class="lhead"><div><h1 class="ltitle">'+esc(o.title||'')+'</h1>'+(o.subtitle?'<div class="lsub">'+esc(o.subtitle)+'</div>':'')+'</div>'+
     '<div class="acts inl">'+(p.actions||[]).map(actBtn).join('')+
     ((p.actions||[]).some(a=>a.tone==='primary')?'':'<button class="btn pri" onclick="toast(\'New — coming soon\')">'+icon('bolt','ic-sm')+'New</button>')+'</div></div>';
  if(p.financials&&p.financials.length){
    h+='<div class="kpis">'+p.financials.map(f=>{ const tc=toneCls(f.tone);
      return '<div class="kpi"><div class="top"><span class="ib '+tc+'">'+icon(cap,'ic-sm')+'</span>'+esc(f.label)+'</div><div class="val '+tc+'">'+esc(fval(f))+'</div>'+(f.foot?'<div class="foot">'+esc(f.foot)+'</div>':'')+'</div>';
    }).join('')+'</div>';
  }
  h+='<div class="card"><div class="toolbar" id="tbar"></div><div class="tbl-wrap" id="tbl"></div><div class="pager" id="pager"></div></div><div id="gtot"></div>';
  document.getElementById('view').innerHTML=h;
  renderToolbar(); renderTable();
}
function setFilter(k,v){ TBL.fval[k]=v; renderTable(); }
function renderToolbar(){
  // Declared filters (payload), else auto-detect a Status column.
  let filts = TBL.filters && TBL.filters.length ? TBL.filters.slice() : (TBL.cols.some(c=>c.key==='status') ? [{key:'status',label:'Status'}] : []);
  let h='<div class="tsearch"><svg class="ic ic-sm" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/></svg>'+
    '<input id="tq" placeholder="Search…" oninput="TBL.q=this.value;renderTable()"></div>';
  filts.forEach(f=>{
    const vals=['All',...Array.from(new Set(TBL.rows.map(r=>cellVal(r[f.key])).filter(Boolean)))];
    if(vals.length<=1) return;
    const cur=TBL.fval[f.key]||'All';
    h+='<label class="chip">'+esc(f.label)+' <select onchange="setFilter(\''+f.key+'\',this.value)">'+vals.map(v=>'<option'+(v===cur?' selected':'')+'>'+esc(v)+'</option>').join('')+'</select></label>';
  });
  if(TBL.dateKey){
    h+='<label class="chip">From <input type="date" value="'+esc(TBL.from)+'" onchange="TBL.from=this.value;TBL.page=1;renderTable()"></label>'+
       '<label class="chip">To <input type="date" value="'+esc(TBL.to)+'" onchange="TBL.to=this.value;TBL.page=1;renderTable()"></label>'+
       ((TBL.from||TBL.to)?'<button class="btn sm" onclick="TBL.from=\'\';TBL.to=\'\';TBL.page=1;renderToolbar();renderTable()">Clear</button>':'');
  }
  h+='<div style="flex:1"></div>';
  // List / Agenda toggle for any dated list + CSV of the current filtered rows.
  if(TBL.dateKey) h+='<div class="vtog"><button class="'+(TBL.mode!=='agenda'?'on':'')+'" onclick="tblMode(\'table\')">List</button><button class="'+(TBL.mode==='agenda'?'on':'')+'" onclick="tblMode(\'agenda\')">Agenda</button></div>';
  h+='<button class="btn sm" onclick="exportTblCsv()" title="Download the filtered rows as CSV">CSV</button>';
  h+='<span class="tinfo" id="tinfo"></span>';
  document.getElementById('tbar').innerHTML=h;
}
function tblMode(m){ TBL.mode=m; try{ window._VIEWMODE[TBL.capKey||'']=m; }catch(e){} renderToolbar(); renderTable(); }
function exportTblCsv(){
  const rows=tblFiltered();
  const cols=TBL.cols.filter(c=>c.key&&c.type!=='action');
  const escCsv=v=>{ v=String(v==null?'':v); return /[",\n]/.test(v)?'"'+v.replace(/"/g,'""')+'"':v; };
  let csv='\uFEFF'+cols.map(c=>escCsv(c.label||c.key)).join(',')+'\n';  // BOM so Excel reads UTF-8
  rows.forEach(r=>{ csv+=cols.map(c=>{ const v=r[c.key]; let s=String(cellVal(v)); if(v&&v.sub) s+=' — '+v.sub; return escCsv(s); }).join(',')+'\n'; });
  const blob=new Blob([csv],{type:'text/csv;charset=utf-8'});
  const url=URL.createObjectURL(blob); const a=document.createElement('a');
  a.href=url; a.download=(TBL.capKey||'export')+'-'+new Date().toISOString().slice(0,10)+'.csv';
  document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url),5000);
  toast('CSV downloaded ('+rows.length+' rows)');
}
function sortBy(k){ if(TBL.sort===k){ TBL.dir=-TBL.dir; } else { TBL.sort=k; TBL.dir=1; } renderTable(); }
/* The current rows after search, filters, date range and sort — shared by the table,
   the Agenda view and the CSV export so all three always agree. */
function tblFiltered(){
  let rows=TBL.rows.slice();
  const q=(TBL.q||'').trim().toLowerCase();
  if(q) rows=rows.filter(r=>TBL.cols.some(c=>{const v=r[c.key]; return String(cellVal(v)).toLowerCase().includes(q) || (v&&v.sub&&String(v.sub).toLowerCase().includes(q));}));
  Object.keys(TBL.fval||{}).forEach(k=>{ const v=TBL.fval[k]; if(v && v!=='All') rows=rows.filter(r=>cellVal(r[k])===v); });
  if(TBL.dateKey){
    const f=TBL.from?Math.floor(Date.parse(TBL.from)/1000):null;
    const t=TBL.to?Math.floor(Date.parse(TBL.to)/1000)+86399:null;
    if(f!=null||t!=null) rows=rows.filter(r=>{ const s=Number(cellSort(r[TBL.dateKey]))||0; if(f!=null&&s<f) return false; if(t!=null&&s>t) return false; return true; });
  }
  if(TBL.sort){ rows.sort((a,b)=>{ let x=cellSort(a[TBL.sort]), y=cellSort(b[TBL.sort]);
    if(typeof x==='string'||typeof y==='string'){ x=String(x).toLowerCase(); y=String(y).toLowerCase(); }
    return (x>y?1:x<y?-1:0)*TBL.dir; }); }
  return rows;
}
/* Phone card renderer for any classic list — turns table rows into design-v1.0 cards. Reuses the same
   cell helpers (cellVal/tone/badge/avatar/row-actions) so a card can't disagree with the table. */
function tblCardsHtml(pageRows){
  if(!pageRows.length) return '<div class="empty" style="padding:40px">'+icon('worklist','ic-lg')+'<div style="margin-top:8px">'+esc(TBL.empty||'No matching records')+'</div></div>';
  var cols=TBL.cols||[], titleCol=cols[0]||{}, badgeCol=null, actCol=null, amtCol=null;
  for(var i=0;i<cols.length;i++){ var c=cols[i]; if(!badgeCol&&c.type==='badge') badgeCol=c; else if(!actCol&&c.type==='action') actCol=c; }
  for(var j=cols.length-1;j>0;j--){ var c2=cols[j]; if(c2!==titleCol&&c2!==badgeCol&&c2!==actCol&&(c2.align==='right'||c2.money||c2.type==='strong')){ amtCol=c2; break; } }
  var subCols=cols.filter(function(c){ return c!==titleCol&&c!==badgeCol&&c!==actCol&&c!==amtCol&&c.key; });
  return '<div class="dtc-list">'+pageRows.map(function(r){
    var tv=r[titleCol.key], isAv=titleCol.type==='avatar', name=esc(cellVal(tv)), tsub=(tv&&tv.sub)?esc(tv.sub):'';
    var av=''; if(isAv){ var img=(tv&&tv.image)||''; av = img
      ? '<div class="dtc-av" style="background-image:url(\''+esc(img)+'\')"></div>'
      : '<div class="dtc-av" style="background:'+avColor(cellVal(tv))+'">'+esc(initials(cellVal(tv)))+'</div>'; }
    var amt=''; if(amtCol){ var avv=cellVal(r[amtCol.key]); if(avv!==''&&avv!=null){ var tcls=(r[amtCol.key]&&r[amtCol.key].tone)?(' '+toneCls(r[amtCol.key].tone)):''; amt='<div class="dtc-amt'+tcls+'">'+esc(avv)+'</div>'; } }
    var metas=[]; if(badgeCol){ var bc=r[badgeCol.key], bv=cellVal(bc); if(bv){ var bt=(bc&&bc.badge)||'default'; metas.push('<span class="badge '+toneBadge(bt)+'"><span class="d"></span>'+esc(bv)+'</span>'); } }
    subCols.forEach(function(c){ var v=cellVal(r[c.key]); if(v!==''&&v!=null) metas.push('<span class="dtc-kv">'+esc(c.label)+' <b>'+esc(v)+'</b></span>'); });
    var acts=''; if(actCol){ var ac=r[actCol.key];
      if(ac&&ac.buttons&&ac.buttons.length) acts='<div class="dtc-acts" onclick="event.stopPropagation()">'+rowActBtnsHtml(ac.buttons)+'</div>';
      else if(ac&&ac.href) acts='<div class="dtc-acts" onclick="event.stopPropagation()"><button class="btn sm" onclick="try{window.open(\''+esc(ac.href)+'\',\'_blank\')}catch(e){location.href=\''+esc(ac.href)+'\'}">'+esc(cellVal(ac)||'Open')+'</button></div>'; }
    var isTask=(TBL.capKey==='tasks' && r.id!=null);   // tasks open the native in-app detail
    var chev=(r.link||isTask)?'<svg class="i dtc-chev" viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>':'';
    var click=isTask?(' onclick="route(\'bos.task\','+Number(r.id)+')" style="cursor:pointer"')
      :(r.link?(' onclick="openLink(\''+esc(r.link)+'\')" style="cursor:pointer"'):'');
    return '<div class="dtc"'+click+'><div class="dtc-top">'+(av?('<div class="dtc-avwrap">'+av+'</div>'):'')
      +'<div class="dtc-main"><div class="dtc-name">'+(name||'—')+'</div>'+(tsub?'<div class="dtc-sub">'+tsub+'</div>':'')+'</div>'
      +'<div class="dtc-r">'+amt+chev+'</div></div>'
      +(metas.length?'<div class="dtc-meta">'+metas.join('')+'</div>':'')+acts+'</div>';
  }).join('')+'</div>';
}
function renderTable(){
  if(TBL.mode==='agenda'){ renderAgenda(); return; }
  let rows=tblFiltered();
  const filtered=rows.length;
  // Grid totals over the full filtered set (not just the visible page).
  if(TBL.totals&&TBL.totals.length){
    const parts=['<div class="gt-row"><span>Count</span><b>'+fmtCount(filtered)+'</b></div>'];
    TBL.totals.forEach(t=>{ const sum=rows.reduce((a,r)=>a+(Number(cellSort(r[t.key]))||0),0);
      parts.push('<div class="gt-row"><span>'+esc(t.label)+'</span><b>'+esc(t.money?curFmt(sum,TBL.cur):fmtCount(sum))+'</b></div>'); });
    document.getElementById('gtot').innerHTML='<div class="gridtotals"><div class="gt-h">Grid totals</div>'+parts.join('')+'</div>';
  } else { const g=document.getElementById('gtot'); if(g) g.innerHTML=''; }
  // Pagination.
  const ps=TBL.pageSize||15, pages=Math.max(1,Math.ceil(filtered/ps));
  if(TBL.page>pages) TBL.page=pages; if(TBL.page<1) TBL.page=1;
  const start=(TBL.page-1)*ps, pageRows=rows.slice(start,start+ps);
  // Phone card mode: on a phone, render each row as a card instead of a sideways-scrolling table.
  // Desktop and any bulk-select list keep the classic table (this branch is fully isolated from it).
  if(isAppUI() && !TBL.selectable){
    document.getElementById('tbl').innerHTML = tblCardsHtml(pageRows);
    const tiC=document.getElementById('tinfo'); if(tiC) tiC.textContent=fmtCount(filtered)+(filtered!==TBL.rows.length?' of '+fmtCount(TBL.rows.length):'');
    const pgC=document.getElementById('pager');
    if(pgC){ if(filtered>ps){ const a=start+1, b=Math.min(start+ps,filtered);
        pgC.innerHTML='<span>'+a+'–'+b+' of '+fmtCount(filtered)+'</span>'+
          '<button onclick="TBL.page--;renderTable()"'+(TBL.page<=1?' disabled':'')+'>Prev</button>'+
          '<span>Page '+TBL.page+' / '+pages+'</span>'+
          '<button onclick="TBL.page++;renderTable()"'+(TBL.page>=pages?' disabled':'')+'>Next</button>';
      } else { pgC.innerHTML=''; } }
    return;
  }
  const arrow=k=> TBL.sort===k ? '<span class="sar">'+(TBL.dir>0?'↑':'↓')+'</span>' : '';
  TBL.fltIds=TBL.selectable?rows.map(r=>r.id).filter(x=>x!=null):[];
  TBL.pageIds=TBL.selectable?pageRows.map(r=>r.id):[];
  const pageAll=pageRows.length&&pageRows.every(r=>TBL.sel[r.id]);
  const pageSome=pageRows.some(r=>TBL.sel[r.id]);
  const selTh=TBL.selectable?('<th class="selh"><input type="checkbox" id="selAllCb" aria-label="Select all on this page" onclick="event.stopPropagation();tselPage(this.checked)"'+(pageAll?' checked':'')+'></th>'):'';
  const th=selTh+TBL.cols.map(c=>'<th class="'+(c.align==='right'?'num':'')+(c.sortable!==false?' srt':'')+(TBL.sort===c.key?' sorted':'')+'"'+(c.sortable!==false?' onclick="sortBy(\''+c.key+'\')"':'')+'><span class="thc">'+esc(c.label)+arrow(c.key)+'</span></th>').join('');
  const selTd=(r,i)=>TBL.selectable?('<td class="selc" onclick="event.stopPropagation()"><input type="checkbox" aria-label="Select row"'+(TBL.sel[r.id]?' checked':'')+' onclick="tselClick(event,\''+esc(String(r.id))+'\','+i+')"></td>'):'';
  // Tasks open the native in-app detail (comments + timeline + completion) on row click — the
  // same route the phone cards use — so the manager register is clickable, not just the phone view.
  const rowClickAttr=r=>{ if(TBL.capKey==='tasks' && r.id!=null) return ' onclick="route(\'bos.task\','+Number(r.id)+')" style="cursor:pointer"'; return r.link?(' onclick="openLink(\''+esc(r.link)+'\')" style="cursor:pointer"'):''; };
  const body = pageRows.length ? pageRows.map((r,i)=>'<tr'+(TBL.selectable&&TBL.sel[r.id]?' class="sel"':'')+rowClickAttr(r)+'>'+selTd(r,i)+TBL.cols.map(c=>cellHtml(r[c.key],c)).join('')+'</tr>').join('')
    : '<tr><td colspan="'+(TBL.cols.length+(TBL.selectable?1:0))+'"><div class="empty" style="padding:40px">'+icon('worklist','ic-lg')+'<div style="margin-top:8px">'+esc(TBL.empty||'No matching records')+'</div></div></td></tr>';
  const nsel=Object.keys(TBL.sel).length;
  let bulkBar='';
  if(TBL.selectable&&nsel){
    const byId={}; TBL.rows.forEach(r=>{ if(r.id!=null) byId[r.id]=r; });
    let sums='';
    (TBL.totals||[]).forEach(t=>{ if(!t.money) return;
      let s=0; Object.keys(TBL.sel).forEach(id=>{ const r=byId[id]; if(r) s+=Number(cellSort(r[t.key]))||0; });
      if(s>0) sums+='<span class="selsum">'+esc(t.label)+': <b>'+esc(curFmt(s,TBL.cur))+'</b></span>'; });
    const canAll=TBL.fltIds.length>nsel;
    bulkBar='<div class="selbar"><span class="selcount"><b>'+nsel+'</b> selected'
      +(canAll?' <a href="javascript:void(0)" onclick="tselAllMatching()">select all '+fmtCount(TBL.fltIds.length)+'</a>':'')
      +'</span>'+sums
      +TBL.bulk.map((b,i)=>'<button class="btn'+(b.tone==='primary'?' pri':'')+'" onclick="tselRun('+i+',this)">'+esc(b.label)+' ('+nsel+')</button>').join('')
      +'<button class="btn ghost" onclick="tselClear()" title="Esc also clears">Clear</button></div>';
  }
  document.getElementById('tbl').innerHTML='<table class="dt"><thead><tr>'+th+'</tr></thead><tbody>'+body+'</tbody></table>'+bulkBar;
  if(TBL.selectable){ const hc=document.getElementById('selAllCb'); if(hc) hc.indeterminate=(!pageAll&&pageSome); }
  const ti=document.getElementById('tinfo'); if(ti) ti.textContent=fmtCount(filtered)+(filtered!==TBL.rows.length?' of '+fmtCount(TBL.rows.length):'');
  const pg=document.getElementById('pager');
  if(pg){ if(filtered>ps){ const a=start+1, b=Math.min(start+ps,filtered);
      pg.innerHTML='<span>'+a+'–'+b+' of '+fmtCount(filtered)+'</span>'+
        '<button onclick="TBL.page--;renderTable()"'+(TBL.page<=1?' disabled':'')+'>Prev</button>'+
        '<span>Page '+TBL.page+' / '+pages+'</span>'+
        '<button onclick="TBL.page++;renderTable()"'+(TBL.page>=pages?' disabled':'')+'>Next</button>';
    } else { pg.innerHTML=''; } }
}
/* Agenda view — the same filtered rows grouped by day of the list's dateKey (CRM-style).
   Generic: first column = title, badge column = status chip, action column = row buttons,
   the rest become the grey sub-line. */
function renderAgenda(){
  const rows=tblFiltered();
  const pg=document.getElementById('pager'); if(pg) pg.innerHTML='';
  const gt=document.getElementById('gtot'); if(gt) gt.innerHTML='';
  const ti=document.getElementById('tinfo'); if(ti) ti.textContent=fmtCount(rows.length)+(rows.length!==TBL.rows.length?' of '+fmtCount(TBL.rows.length):'');
  const el=document.getElementById('tbl'); if(!el) return;
  if(!rows.length){ el.innerHTML='<div class="empty" style="padding:40px">'+icon('worklist','ic-lg')+'<div style="margin-top:8px">'+esc(TBL.empty||'No matching records')+'</div></div>'; return; }
  const titleCol=TBL.cols[0]||{};
  const badgeCol=TBL.cols.find(c=>c.type==='badge');
  const actCol=TBL.cols.find(c=>c.type==='action');
  const subCols=TBL.cols.filter(c=>c!==titleCol&&c!==badgeCol&&c!==actCol&&c.key&&c.key!==TBL.dateKey);
  const dayKey=ts=>{ const d=new Date(ts*1000); return d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0'); };
  const groups={}, order=[];
  rows.forEach(r=>{ const ts=Number(cellSort(r[TBL.dateKey]))||0; const gk=ts?dayKey(ts):'nodate';
    if(!groups[gk]){ groups[gk]=[]; order.push(gk); } groups[gk].push(r); });
  order.sort((a,b)=>a==='nodate'?1:(b==='nodate'?-1:(a<b?-1:1)));
  const now=new Date(); const tk=now.getFullYear()+'-'+String(now.getMonth()+1).padStart(2,'0')+'-'+String(now.getDate()).padStart(2,'0');
  let h='<div class="agenda">';
  order.forEach(gk=>{
    let lbl='No date';
    if(gk!=='nodate'){ const d=new Date(gk+'T00:00:00');
      lbl=d.toLocaleDateString(undefined,{weekday:'short',day:'numeric',month:'short',year:'numeric'});
      if(gk===tk) lbl+=' · Today'; }
    h+='<div class="ag-day'+(gk===tk?' today':'')+'">'+esc(lbl)+' <span style="color:var(--faint);font-weight:600">· '+groups[gk].length+'</span></div>';
    groups[gk].sort((a,b)=>(Number(cellSort(a[TBL.dateKey]))||0)-(Number(cellSort(b[TBL.dateKey]))||0)).forEach(r=>{
      const ts=Number(cellSort(r[TBL.dateKey]))||0;
      const tm=ts?new Date(ts*1000).toTimeString().slice(0,5):'—';
      const bc=badgeCol?r[badgeCol.key]:null;
      const sub=subCols.map(c=>{ const v=cellVal(r[c.key]); return (v&&v!=='—')?v:''; }).filter(Boolean).join(' · ');
      h+='<div class="ag-row'+(r.link?' clk':'')+'"'+(r.link?' onclick="openLink(\''+esc(r.link)+'\')"':'')+'>'
        +'<div class="ag-time">'+esc(tm)+'</div>'
        +'<div class="ag-main"><div class="prim">'+esc(cellVal(r[titleCol.key]))+'</div>'+(sub?'<div class="mut" style="font-size:12px">'+esc(sub)+'</div>':'')+'</div>'
        +(bc&&cellVal(bc)?('<span class="badge '+toneBadge((bc&&bc.badge)||'default')+'"><span class="d"></span>'+esc(cellVal(bc))+'</span>'):'')
        +((actCol&&r[actCol.key]&&r[actCol.key].buttons)?('<div class="rowacts">'+rowActBtnsHtml(r[actCol.key].buttons)+'</div>'):'')
        +'</div>';
    });
  });
  h+='</div>';
  el.innerHTML=h;
}
/* UCRM-style bulk selection (2026-07-18, UX-studied v2): shift-range, select-all-matching,
   selection money totals, busy states, Esc-to-clear, indeterminate header. */
function tselClick(ev,id,idx){ ev.stopPropagation();
  const on=!TBL.sel[id];
  if(ev.shiftKey && TBL.lastIdx!=null && Array.isArray(TBL.pageIds)){
    const a=Math.min(TBL.lastIdx,idx), b=Math.max(TBL.lastIdx,idx);
    for(let k=a;k<=b;k++){ const rid=TBL.pageIds[k]; if(rid==null) continue;
      if(on)TBL.sel[rid]=1; else delete TBL.sel[rid]; }
  } else { if(on)TBL.sel[id]=1; else delete TBL.sel[id]; }
  TBL.lastIdx=idx; renderTable(); }
function tselPage(on){ (TBL.pageIds||[]).forEach(rid=>{ if(rid==null) return;
    if(on)TBL.sel[rid]=1; else delete TBL.sel[rid]; });
  TBL.lastIdx=null; renderTable(); }
function tselAllMatching(){ (TBL.fltIds||[]).forEach(rid=>{ if(rid!=null)TBL.sel[rid]=1; }); renderTable(); }
function tselClear(){ TBL.sel={}; TBL.lastIdx=null; renderTable(); }
function tselRun(i,btn){ const b=TBL.bulk[i]; if(!b) return; const ids=Object.keys(TBL.sel);
  if(!ids.length){ toast('Select some rows first', true); return; }
  if(b.confirm && !window.confirm(b.confirm.replace('{n}', ids.length))) return;
  // v4.7 — "client" bulk actions run inside the shell. sendbus: hand the selected orders to the
  // Consignments screen (Kampala → Juba bus), which offers to load them onto a consignment.
  if(b.client==='sendbus'){
    try{ localStorage.setItem('cons_add_ids', JSON.stringify({ids:ids.map(Number), ts:Date.now()})); }catch(e){}
    toast('🚌 '+ids.length+' order'+(ids.length===1?'':'s')+' ready to load — pick or create the consignment');
    TBL.sel={}; route('consignments'); return;
  }
  if((b.method||'GET')==='GET'){ window.open(b.endpoint+(b.endpoint.includes('?')?'&':'?')+'ids='+ids.join(','), '_blank'); return; }
  (async()=>{ let old='';
    if(btn){ old=btn.textContent; btn.disabled=true; btn.textContent=old.replace(/\s*\(\d+\)$/,'')+'…'; }
    try{ const res=await post(b.endpoint,{ids:ids.map(Number)});
      toast(res&&res.ok?(res.message||'Done'):((res&&res.error)||'Failed'), !(res&&res.ok));
      if(res&&res.ok){ TBL.sel={}; TBL.lastIdx=null; refresh(); return; }
    } finally { if(btn){ btn.disabled=false; btn.textContent=old; } } })(); }
document.addEventListener('keydown',e=>{ if(e.key==='Escape' && TBL && TBL.selectable && Object.keys(TBL.sel||{}).length
  && !document.querySelector('.mwrap,.modal,[data-modal-open]')){ tselClear(); } });
function cellHtml(c,col){
  const align=col.align==='right'?' num':'';
  if(col.type==='avatar'){ const nm=cellVal(c), sub=(c&&c.sub)||'', img=(c&&c.image)||'';
    const av = img ? '<div class="av-s" style="background:#eef0f3;background-image:url(\''+esc(img)+'\');background-size:cover;background-position:center"></div>' : '<div class="av-s" style="background:'+avColor(nm)+'">'+initials(nm)+'</div>';
    return '<td><div class="cellflex">'+av+'<div><div class="prim">'+esc(nm)+'</div>'+(sub?'<div class="mut">'+esc(sub)+'</div>':'')+'</div></div></td>'; }
  if(col.type==='strong'){ return '<td class="prim'+align+'">'+esc(cellVal(c))+'</td>'; }
  if(col.type==='badge'){ const v=cellVal(c), tone=(c&&c.badge)||'default';
    return '<td>'+(v?'<span class="badge '+toneBadge(tone)+'"><span class="d"></span>'+esc(v)+'</span>':'—')+'</td>'; }
  if(col.type==='action'){
    // Multiple per-row icon buttons (href opens, post fires a mutation then refreshes).
    if(c && c.buttons && c.buttons.length){
      return '<td><div class="rowacts">'+rowActBtnsHtml(c.buttons)+'</div></td>';
    }
    const href=(c&&c.href)||''; const label=cellVal(c)||'Open';
    return '<td>'+(href?'<button class="btn sm" onclick="event.stopPropagation();try{window.open(\''+esc(href)+'\',\'_blank\')}catch(e){location.href=\''+esc(href)+'\'}">'+icon((c&&c.icon)||'download','ic-sm')+esc(label)+'</button>':'—')+'</td>'; }
  const tone=(c&&c.tone)?(' '+toneCls(c.tone)):'';
  return '<td class="'+align+tone+'">'+esc(cellVal(c))+'</td>';
}

/* ================= Storefront merchandising editors (native v3) =================
   Client-rendered pages wired to the existing /papi endpoints (session-authed, same as the
   classic panel). No server workspace payload — route() special-cases these two keys. */
var SFCFG={enabled:false,title:'',autoscroll:5,'default':'',promotions:[],businesses:[]};
var SFKITS=[], SFKCOLLS=[], _sfPhoto=null;
var SF_ICONS=['home','hotels','restaurants','retail','supermarkets','wholesale','catering','offices','bakeries','cleaning','shop'];
function sfEl(id){return document.getElementById(id);}
function sfj(s){return JSON.stringify(String(s==null?'':s)).replace(/"/g,'&quot;');}
function sfHiddenFile(){ var f=sfEl('sfPhotoFile'); if(!f){ f=document.createElement('input'); f.type='file'; f.accept='image/*'; f.id='sfPhotoFile'; f.style.display='none'; f.onchange=sfPhotoPicked; document.body.appendChild(f);} return f; }
function sfShrink(file,maxDim,q){ maxDim=maxDim||1400;q=q||0.82; return new Promise(function(res){ var rd=new FileReader(); rd.onerror=function(){res(null);}; rd.onload=function(){ var img=new Image(); img.onload=function(){ var w=img.width,h=img.height,s=Math.min(1,maxDim/Math.max(w,h)); var cw=Math.max(1,Math.round(w*s)),ch=Math.max(1,Math.round(h*s)); try{ var c=document.createElement('canvas'); c.width=cw;c.height=ch; var x=c.getContext('2d'); x.fillStyle='#fff';x.fillRect(0,0,cw,ch);x.drawImage(img,0,0,cw,ch); res(c.toDataURL('image/jpeg',q)); }catch(e){res(rd.result);} }; img.onerror=function(){res(rd.result);}; img.src=rd.result; }; rd.readAsDataURL(file); }); }
function sfTabs(active){
  return '<div class="sftabs">'
    +'<button class="sftab'+(active==='collections'?' on':'')+'" onclick="route(\'storefront.collections\')">Shop for Every Need</button>'
    +'<button class="sftab'+(active==='kits'?' on':'')+'" onclick="route(\'storefront.kits\')">Ready-made Kits</button>'
    +'<button class="sftab'+(active==='delivery'?' on':'')+'" onclick="route(\'storefront.delivery\')">Delivery &amp; Free Shipping</button></div>';
}

/* ---------- Delivery & Free Shipping (per-city free-delivery thresholds) ---------- */
var SFDEL=[], SFDEL_CUR='USD';
function sfDeliveryPage(){
  sfEl('view').innerHTML='<div class="wrap">'+sfTabs('delivery')+'<div class="card"><div class="b">Loading…</div></div></div>';
  getQuery('/papi/delivery-rules',{}).then(function(d){
    SFDEL_CUR=(d&&d.currency)||'USD';
    SFDEL=(d&&Array.isArray(d.free_rules))?d.free_rules.slice():[];
    if(!SFDEL.length){
      // Seed a row per branch so the merchant just types amounts (Juba pre-filled at 50).
      var br=(d&&Array.isArray(d.branches)&&d.branches.length)?d.branches:['Juba','Kampala'];
      SFDEL=br.map(function(c){return {city:c, free_over:(/juba/i.test(c)?50:(/kampala/i.test(c)?20:0))};});
    }
    sfDeliveryRender();
  }).catch(function(){ SFDEL=[{city:'Juba',free_over:50}]; sfDeliveryRender(); });
}
function sfDeliveryRender(){
  var head='<div class="card"><div class="h">Delivery &amp; Free Shipping<button class="btn pri" style="margin-left:auto" onclick="sfSaveDelivery()">Save</button></div>'
    +'<div class="b" style="color:var(--muted);font-size:13px">Set a free-delivery threshold per city. When a customer selects that city and their cart reaches the amount, the storefront shows a “FREE delivery” badge and a progress nudge, and the order note tells you delivery is free. Amounts are in '+esc(SFDEL_CUR)+'. Set 0 to disable free delivery for a city.</div></div>';
  var rows=SFDEL.map(function(r,i){
    return '<div class="sfrow" style="margin:8px 0;align-items:flex-end;gap:10px">'
      +'<div class="sffield" style="flex:1;min-width:160px"><label>City</label><input class="sfin" value="'+esc(r.city||'')+'" placeholder="e.g. Juba" onchange="sfDelUpd('+i+',\'city\',this.value)"></div>'
      +'<div class="sffield"><label>Free delivery over ('+esc(SFDEL_CUR)+')</label><input class="sfin" type="number" min="0" step="0.01" style="width:150px" value="'+(Number(r.free_over)||0)+'" onchange="sfDelUpd('+i+',\'free_over\',this.value)"></div>'
      +'<button class="btn sm danger" onclick="sfDelDel('+i+')">Remove</button></div>';
  }).join('');
  var body='<div class="card"><div class="b">'+(rows||'<div style="color:var(--muted)">No cities yet — add one.</div>')+'<button class="btn sm" style="margin-top:10px" onclick="sfDelAdd()">+ Add city</button></div></div>';
  sfEl('view').innerHTML='<div class="wrap">'+sfTabs('delivery')+head+'<div style="height:14px"></div>'+body+'</div>';
}
function sfDelUpd(i,k,v){ if(!SFDEL[i])return; SFDEL[i][k]=(k==='free_over')?Math.max(0,parseFloat(v)||0):v; }
function sfDelAdd(){ SFDEL.push({city:'',free_over:0}); sfDeliveryRender(); }
function sfDelDel(i){ SFDEL.splice(i,1); sfDeliveryRender(); }
async function sfSaveDelivery(){
  var rules=SFDEL.map(function(r){return {city:String(r.city||'').trim(),free_over:Math.max(0,parseFloat(r.free_over)||0)};}).filter(function(r){return r.city&&r.free_over>0;});
  var d=await post('/papi/delivery-rules-save',{free_rules:rules});
  if(d&&d.ok){ if(Array.isArray(d.free_rules))SFDEL=d.free_rules.length?d.free_rules:SFDEL; sfDeliveryRender(); toast('Delivery rules saved'); } else toast((d&&d.error)||'Could not save',true);
}

/* ---------- Shop for Every Need ---------- */
function sfCollectionsPage(){
  sfEl('view').innerHTML='<div class="wrap">'+sfTabs('collections')+'<div class="card"><div class="b">Loading…</div></div></div>';
  getQuery('/papi/business-banners',{}).then(function(d){
    var c=(d&&d.config)||{};
    SFCFG={enabled:!!c.enabled,title:c.title||'',autoscroll:(c.autoscroll==null?5:c.autoscroll),'default':c['default']||'',promotions:Array.isArray(c.promotions)?c.promotions:[],businesses:Array.isArray(c.businesses)?c.businesses:[]};
    sfCollectionsRender();
  }).catch(function(){ SFCFG={enabled:false,title:'',autoscroll:5,'default':'',promotions:[],businesses:[]}; sfCollectionsRender(); });
}
function sfCollectionsRender(){
  var defOpts='<option value="">First / most-used</option>'+SFCFG.businesses.map(function(b){return '<option value="'+sfj(b.id)+'"'+(SFCFG['default']===b.id?' selected':'')+'>'+esc(b.label||b.id)+'</option>';}).join('');
  var head='<div class="card"><div class="h">Shop for Every Need'
    +'<label class="sfchk" style="margin-left:auto"><input type="checkbox" '+(SFCFG.enabled?'checked':'')+' onchange="sfSet(\'enabled\',this.checked)"> Show on storefront</label></div>'
    +'<div class="b"><div class="sfrow">'
      +'<div class="sffield" style="flex:1;min-width:200px"><label>Section title</label><input class="sfin" value="'+esc(SFCFG.title||'')+'" placeholder="Shop for Every Need" onchange="sfSet(\'title\',this.value)"></div>'
      +'<div class="sffield"><label>Auto-scroll (sec, 0=off)</label><input class="sfin" type="number" min="0" max="30" style="width:120px" value="'+(SFCFG.autoscroll==null?5:SFCFG.autoscroll)+'" onchange="sfSet(\'autoscroll\',this.value)"></div>'
      +'<div class="sffield"><label>Default for new visitors</label><select class="sfsel" onchange="sfSet(\'default\',this.value)">'+defOpts+'</select></div>'
      +'<button class="btn" onclick="sfLoadStarter()">Load starter template</button>'
      +'<button class="btn pri" style="margin-left:auto" onclick="sfSaveCollections()">Save</button>'
    +'</div>'
    +'<div class="sffield" style="margin-top:12px"><label>Current promotions — product names, comma-separated (shown as a homepage row)</label><input class="sfin" value="'+esc((SFCFG.promotions||[]).join(', '))+'" placeholder="Aluminium Foil 150m, Cling Film 300m" onchange="sfSetProm(this.value)"></div>'
    +'</div></div>';
  var body=SFCFG.businesses.length?SFCFG.businesses.map(function(b,bi){return sfBizCard(b,bi);}).join(''):'<div class="card"><div class="b" style="color:var(--muted)">No collections yet. Add one, or load the starter template.</div></div>';
  sfEl('view').innerHTML='<div class="wrap">'+sfTabs('collections')+head+'<div style="height:14px"></div>'+body
    +'<button class="btn" onclick="sfAddBiz()" style="margin-top:12px">+ Add collection</button></div>';
}
function sfBizCard(b,bi){
  var icons=SF_ICONS.map(function(k){return '<option value="'+k+'"'+((b.icon||'')===k?' selected':'')+'>'+k+'</option>';}).join('');
  var slides=(b.slides||[]).map(function(s,si){return sfSlideRow(s,bi,si);}).join('');
  var colls=(b.collections||[]).map(function(c,ci){return sfCollRow(c,bi,ci);}).join('');
  var best=Array.isArray(b.bestsellers)?b.bestsellers.join(', '):(b.bestsellers||'auto');
  var card=b.card_image?('<img class="sfphoto" src="'+esc(b.card_image)+'">'):('<div class="sfphoto">card</div>');
  return '<div class="card" style="margin-bottom:14px"><div class="h">'
    +'<input class="sfin" value="'+esc(b.label||'')+'" placeholder="Collection label" style="font-weight:700;max-width:230px" onchange="sfUpd('+bi+',\'label\',this.value)">'
    +'<label class="sfchk" style="margin-left:8px"><input type="checkbox" '+(b.active!==false?'checked':'')+' onchange="sfUpd('+bi+',\'active\',this.checked)"> Active</label>'
    +'<div style="margin-left:auto;display:flex;gap:6px"><button class="btn sm" onclick="sfMoveBiz('+bi+',-1)" title="Move up">↑</button><button class="btn sm" onclick="sfMoveBiz('+bi+',1)" title="Move down">↓</button><button class="btn sm danger" onclick="sfDelBiz('+bi+')">Remove</button></div></div>'
    +'<div class="b">'
      +'<div class="sfrow" style="margin-bottom:12px">'
        +'<div style="text-align:center">'+card+'<div style="margin-top:5px"><button class="btn sm" onclick="sfPickPhoto(\'card\','+bi+')">Card photo</button>'+(b.card_image?' <button class="btn sm danger" onclick="sfClearCard('+bi+')">✕</button>':'')+'</div></div>'
        +'<div class="sffield" style="flex:1;min-width:160px"><label>One-line description</label><input class="sfin" value="'+esc(b.sub||'')+'" placeholder="Everyday basics" onchange="sfUpd('+bi+',\'sub\',this.value)"></div>'
        +'<div class="sffield"><label>Icon</label><select class="sfsel" onchange="sfUpd('+bi+',\'icon\',this.value)">'+icons+'</select></div>'
        +'<div class="sffield"><label>ID</label><input class="sfin" value="'+esc(b.id||'')+'" style="width:110px" onchange="sfUpd('+bi+',\'id\',this.value)"></div>'
      +'</div>'
      +'<div class="sfrow" style="margin-bottom:6px">'
        +'<div class="sffield" style="flex:1;min-width:200px"><label>Match categories (comma)</label><input class="sfin" value="'+esc((b.match&&b.match.categories||[]).join(', '))+'" onchange="sfMatch('+bi+',\'categories\',this.value)"></div>'
        +'<div class="sffield" style="flex:1;min-width:200px"><label>Match keywords (comma)</label><input class="sfin" value="'+esc((b.match&&b.match.keywords||[]).join(', '))+'" onchange="sfMatch('+bi+',\'keywords\',this.value)"></div>'
      +'</div>'
      +'<label class="sfchk" style="margin-bottom:12px"><input type="checkbox" '+(b.match&&b.match.bulk?'checked':'')+' onchange="sfMatchBulk('+bi+',this.checked)"> Also include all bulk / carton items (pack or MOQ &gt; 1)</label>'
      +'<div style="font-weight:700;font-size:13px;margin:2px 0 8px">Hero slides (auto-scroll)</div>'+slides
      +'<button class="btn sm" style="margin-top:4px" onclick="sfAddSlide('+bi+')">+ Add slide</button>'
      +'<div style="font-weight:700;font-size:13px;margin:14px 0 8px">Collections</div>'+colls
      +'<button class="btn sm" style="margin-top:4px" onclick="sfAddColl('+bi+')">+ Add collection</button>'
      +'<div class="sffield" style="margin-top:12px"><label>Best sellers (product names, comma — or "auto")</label><input class="sfin" value="'+esc(best)+'" placeholder="auto" onchange="sfUpd('+bi+',\'bestsellers\',this.value)"></div>'
    +'</div></div>';
}
function sfSlideRow(s,bi,si){
  var img=s.image_url?('<img class="sfphoto" src="'+esc(s.image_url)+'">'):('<div class="sfphoto" style="background:'+(s.theme||'#123a2a')+';color:#fff">hero</div>');
  return '<div class="sfmini"><div class="sfrow">'
    +'<div style="text-align:center">'+img+'<div style="margin-top:5px"><button class="btn sm" onclick="sfPickPhoto(\'slide\','+bi+','+si+')">Upload</button>'+(s.image_url?' <button class="btn sm danger" onclick="sfClearSlide('+bi+','+si+')">✕</button>':'')+'</div></div>'
    +'<div style="flex:1;min-width:220px">'
      +'<div class="sfrow"><input class="sfin" style="flex:1" value="'+esc(s.kick||'')+'" placeholder="Kicker (Hotel Supplies)" onchange="sfSlide('+bi+','+si+',\'kick\',this.value)"><input class="sfin" style="flex:1" value="'+esc(s.cta_label||'')+'" placeholder="Button (Shop Collection)" onchange="sfSlide('+bi+','+si+',\'cta_label\',this.value)"></div>'
      +'<input class="sfin" style="width:100%;margin-top:6px" value="'+esc(s.title||'')+'" placeholder="Headline — wrap a word in *stars* to highlight" onchange="sfSlide('+bi+','+si+',\'title\',this.value)">'
      +'<input class="sfin" style="width:100%;margin-top:6px" value="'+esc((s.points||[]).join(' | '))+'" placeholder="Bullets separated by |" onchange="sfPoints('+bi+','+si+',this.value)">'
      +'<div class="sfrow" style="margin-top:6px"><input class="sfin" style="flex:1" value="'+esc(s.theme||'')+'" placeholder="CSS background (if no photo)" onchange="sfSlide('+bi+','+si+',\'theme\',this.value)"><input class="sfin" style="flex:1" value="'+esc(s.cta||'')+'" placeholder="Button target (blank=shop this)" onchange="sfSlide('+bi+','+si+',\'cta\',this.value)"><label class="sfchk"><input type="checkbox" '+(s.gold?'checked':'')+' onchange="sfSlide('+bi+','+si+',\'gold\',this.checked)"> Gold</label><button class="btn sm danger" onclick="sfDelSlide('+bi+','+si+')">Remove</button></div>'
    +'</div></div></div>';
}
function sfCollRow(c,bi,ci){
  return '<div class="sfrow" style="margin-bottom:6px"><input class="sfin" style="flex:1" value="'+esc(c.label||'')+'" placeholder="Collection label" onchange="sfColl('+bi+','+ci+',\'label\',this.value)"><input class="sfin" style="flex:1" value="'+esc(c.cat||'')+'" placeholder="Jump to category" onchange="sfColl('+bi+','+ci+',\'cat\',this.value)"><input class="sfin" style="flex:1" value="'+esc(c.keywords||'')+'" placeholder="or keyword" onchange="sfColl('+bi+','+ci+',\'keywords\',this.value)"><button class="btn sm danger" onclick="sfDelColl('+bi+','+ci+')">✕</button></div>';
}
function sfB(bi){return SFCFG.businesses[bi];}
function sfSet(k,v){ if(k==='autoscroll')v=Math.max(0,Math.min(30,parseInt(v,10)||0)); SFCFG[k]=v; }
function sfSetProm(v){ SFCFG.promotions=String(v).split(',').map(function(x){return x.trim();}).filter(Boolean); }
function sfUpd(bi,k,v){var b=sfB(bi);if(!b)return; if(k==='bestsellers'){v=String(v).trim();b.bestsellers=(v===''||v.toLowerCase()==='auto')?'auto':v.split(',').map(function(x){return x.trim();}).filter(Boolean);} else b[k]=v; if(k==='label'||k==='id')sfCollectionsRender();}
function sfMatch(bi,k,v){var b=sfB(bi);if(!b)return;b.match=b.match||{};b.match[k]=v.split(',').map(function(x){return x.trim();}).filter(Boolean);}
function sfMatchBulk(bi,on){var b=sfB(bi);if(!b)return;b.match=b.match||{};b.match.bulk=!!on;}
function sfSlide(bi,si,k,v){var b=sfB(bi);if(!b||!b.slides||!b.slides[si])return;b.slides[si][k]=v;}
function sfPoints(bi,si,v){var b=sfB(bi);if(!b||!b.slides||!b.slides[si])return;b.slides[si].points=v.split('|').map(function(x){return x.trim();}).filter(Boolean);}
function sfColl(bi,ci,k,v){var b=sfB(bi);if(!b||!b.collections||!b.collections[ci])return;b.collections[ci][k]=v;}
function sfAddBiz(){SFCFG.businesses.push({id:'biz'+(SFCFG.businesses.length+1),label:'New Collection',sub:'',icon:'shop',active:true,card_image:'',match:{categories:[],keywords:[],bulk:false},slides:[{kick:'',title:'New headline',points:[],cta_label:'Shop now',cta:'',gold:false,theme:'linear-gradient(135deg,#123a2a,#0b5c46)',image_url:''}],collections:[],bestsellers:'auto'});sfCollectionsRender();}
function sfDelBiz(bi){if(!confirm('Remove this collection?'))return;SFCFG.businesses.splice(bi,1);sfCollectionsRender();}
function sfMoveBiz(bi,dir){var j=bi+dir;if(j<0||j>=SFCFG.businesses.length)return;var a=SFCFG.businesses,t=a[bi];a[bi]=a[j];a[j]=t;sfCollectionsRender();}
function sfAddSlide(bi){var b=sfB(bi);if(!b)return;b.slides=b.slides||[];b.slides.push({kick:'',title:'New headline',points:[],cta_label:'Shop now',cta:'',gold:false,theme:'linear-gradient(135deg,#123a2a,#0b5c46)',image_url:''});sfCollectionsRender();}
function sfDelSlide(bi,si){var b=sfB(bi);if(!b||!b.slides)return;b.slides.splice(si,1);sfCollectionsRender();}
function sfAddColl(bi){var b=sfB(bi);if(!b)return;b.collections=b.collections||[];b.collections.push({label:'',cat:'',keywords:'',icon:'shop',image_url:''});sfCollectionsRender();}
function sfDelColl(bi,ci){var b=sfB(bi);if(!b||!b.collections)return;b.collections.splice(ci,1);sfCollectionsRender();}
function sfClearCard(bi){var b=sfB(bi);if(b){b.card_image='';sfCollectionsRender();}}
function sfClearSlide(bi,si){var b=sfB(bi);if(b&&b.slides&&b.slides[si]){b.slides[si].image_url='';sfCollectionsRender();}}
function sfPickPhoto(type,bi,si){_sfPhoto={type:type,bi:bi,si:si};var f=sfHiddenFile();f.value='';f.click();}
async function sfPhotoPicked(){
  var f=sfEl('sfPhotoFile');var file=f&&f.files&&f.files[0];if(!file||!_sfPhoto)return;
  toast('Uploading photo…');
  var data=await sfShrink(file,1600,.82); if(!data){toast('Could not read image',true);return;}
  var d=await post('/papi/upload-image',{name:(file.name||'img')+'.jpg',data:data});
  if(d&&d.ok&&d.url){
    if(_sfPhoto.type==='kit'){ var k=SFKITS[_sfPhoto.bi]; if(k){k.image_url=d.url;sfKitsRender();} }
    else { var b=sfB(_sfPhoto.bi); if(b){ if(_sfPhoto.type==='card')b.card_image=d.url; else if(b.slides&&b.slides[_sfPhoto.si])b.slides[_sfPhoto.si].image_url=d.url; sfCollectionsRender(); } }
    toast('Photo added');
  } else toast('Upload failed — try a smaller image',true);
}
async function sfSaveCollections(){
  var d=await post('/papi/business-banners-save',{config:SFCFG});
  if(d&&d.ok){ if(d.config)SFCFG=d.config; sfCollectionsRender(); toast('Saved'); } else toast((d&&d.error)||'Could not save',true);
}
function sfLoadStarter(){
  if(SFCFG.businesses.length && !confirm('Replace the current collections with the starter template?'))return;
  var g={home:'linear-gradient(135deg,#0b5c46,#0b7a3b)',hotels:'linear-gradient(135deg,#123a6b,#1e5aa8)',restaurants:'linear-gradient(135deg,#5a2c12,#9a4a12)',retail:'linear-gradient(135deg,#123018,#0b7a3b)',wholesale:'linear-gradient(135deg,#2a1a4a,#5a3a9a)',catering:'linear-gradient(135deg,#0d4a50,#12959f)',offices:'linear-gradient(135deg,#334155,#556579)'};
  var m=function(cats,kws,bulk){return {categories:cats,keywords:kws,bulk:!!bulk};};
  var sl=function(kick,title,pts,cta,gold,theme){return {kick:kick,title:title,points:pts,cta_label:cta,cta:'',gold:!!gold,theme:theme,image_url:''};};
  var col=function(label,cat){return {label:label,cat:cat,keywords:'',icon:'shop',image_url:''};};
  SFCFG.enabled=true;SFCFG.title='Shop for Every Need';SFCFG.autoscroll=5;SFCFG['default']='wholesale';
  SFCFG.businesses=[
    {id:'wholesale',label:'Wholesale Deals',sub:'Bulk & carton pricing',icon:'wholesale',active:true,card_image:'',match:m([],['carton','bulk','pack'],true),slides:[sl('Wholesale Deals','Bulk Buying Made *Easy*',['Carton & bulk packs','Lowest MOQ, best margins','Fast quotations'],'View Wholesale Deals',true,g.wholesale)],collections:[],bestsellers:'auto'},
    {id:'restaurants',label:'Restaurant Supplies',sub:'Kitchen & packaging',icon:'restaurants',active:true,card_image:'',match:m(['Disposables, Foil & Wraps','Recipe Mixes & Masalas','Ground Spices (Powders)','Whole Spices','Sauces & Condiments','Cleaning & Hygiene'],['foil','cling','container','glove','oil','masala','spice','sauce','packaging','clean']),slides:[sl('Restaurant Supplies','Kitchen & Packaging *Essentials*',['Foil, cling & containers','Disposable gloves & kitchen cleaning','Packaging that sells'],'Shop Restaurant Collection',false,g.restaurants)],collections:[col('Foil & Wraps','Disposables, Foil & Wraps'),col('Recipe Mixes & Masalas','Recipe Mixes & Masalas')],bestsellers:'auto'},
    {id:'hotels',label:'Hotel Supplies',sub:'Housekeeping & guest',icon:'hotels',active:true,card_image:'',match:m(['Cleaning & Hygiene','Disposables, Foil & Wraps'],['towel','tissue','soap','shampoo','clean','foil','sanitiser','detergent','amenity']),slides:[sl('Hotel Supplies','Everything Your *Hotel* Needs',['Housekeeping & guest amenities','Foil, cling & kitchen','Cleaning chemicals'],'Shop Hotel Collection',false,g.hotels)],collections:[col('Cleaning & Hygiene','Cleaning & Hygiene'),col('Foil & Disposables','Disposables, Foil & Wraps')],bestsellers:'auto'},
    {id:'retail',label:'Retail Store Essentials',sub:'Fast-moving stock',icon:'retail',active:true,card_image:'',match:m(['Snacks & Namkeen','Sauces & Condiments','Recipe Mixes & Masalas','Dal & Pulses','Rice, Biryani & Ready Mixes','Cleaning & Hygiene'],['snack','sauce','rice','dal','masala','tea','sugar','clean','tissue']),slides:[sl('Retail Store Essentials','Stock Your *Shelves* Faster',['Fast-moving consumer goods','Best-selling grocery & household','Cleaning & snacks'],'Browse Retail Best Sellers',false,g.retail)],collections:[col('Snacks & Namkeen','Snacks & Namkeen'),col('Rice & Ready Mixes','Rice, Biryani & Ready Mixes'),col('Cleaning','Cleaning & Hygiene')],bestsellers:'auto'},
    {id:'home',label:'Home Essentials',sub:'Daily groceries & household',icon:'home',active:true,card_image:'',match:m(['Cleaning & Hygiene','Snacks & Namkeen','Rice, Biryani & Ready Mixes','Dal & Pulses','Sauces & Condiments'],['oil','rice','sugar','tea','tissue','clean','snack','soap','detergent','flour','salt']),slides:[sl('Home Essentials','Everything Your *Home* Needs',['Cooking, cleaning & everyday basics','Great prices, fast delivery','Buy one, or buy in bulk'],'Shop Home Essentials',false,g.home)],collections:[col('Cooking & Staples','Rice, Biryani & Ready Mixes'),col('Cleaning & Hygiene','Cleaning & Hygiene'),col('Snacks','Snacks & Namkeen')],bestsellers:'auto'},
    {id:'catering',label:'Catering Supplies',sub:'Events & food service',icon:'catering',active:true,card_image:'',match:m(['Disposables, Foil & Wraps','Recipe Mixes & Masalas','Ground Spices (Powders)'],['plate','cup','cutlery','foil','cling','container','napkin','straw','masala']),slides:[sl('Catering & Events','Everything for *Catering & Events*',['Disposable plates, cups & cutlery','Containers, foil & cling','Serve hundreds, easily'],'Shop Catering Supplies',false,g.catering)],collections:[col('Disposables & Foil','Disposables, Foil & Wraps')],bestsellers:'auto'},
    {id:'offices',label:'Office Supplies',sub:'Pantry & cleaning',icon:'offices',active:true,card_image:'',match:m(['Cleaning & Hygiene','Snacks & Namkeen','Sauces & Condiments'],['tea','coffee','tissue','clean','sugar','water','pantry','biscuit']),slides:[sl('Office Supplies','Keep Your *Office* Running Smoothly',['Tea, coffee & pantry','Tissue & cleaning','Stationery & essentials'],'Shop Office Supplies',false,g.offices)],collections:[col('Pantry & Snacks','Snacks & Namkeen'),col('Cleaning & Tissue','Cleaning & Hygiene')],bestsellers:'auto'}
  ];
  sfCollectionsRender();toast('Starter loaded — review, add photos, then Save');
}

/* ---------- Ready-made Kits ---------- */
function sfKitsPage(){
  sfEl('view').innerHTML='<div class="wrap">'+sfTabs('kits')+'<div class="card"><div class="b">Loading…</div></div></div>';
  // collection options come from the banners config; products from the panel feed (for the datalist)
  getQuery('/papi/business-banners',{}).then(function(d){ var bs=(d&&d.config&&d.config.businesses)||[]; SFKCOLLS=bs.map(function(b){return {id:b.id,label:b.label||b.id};}); }).catch(function(){SFKCOLLS=[];})
  .finally(function(){
    getQuery('/papi/products',{}).then(function(d){ var rows=(d&&d.products)||[]; window.__SFPROD=rows.map(function(p){return p['Product Name']||'';}).filter(Boolean); }).catch(function(){window.__SFPROD=[];})
    .finally(function(){
      getQuery('/papi/kits',{}).then(function(d){ SFKITS=(d&&Array.isArray(d.kits))?d.kits:[]; sfKitsRender(); }).catch(function(){SFKITS=[];sfKitsRender();});
    });
  });
}
function sfKitsRender(){
  var dl='<datalist id="sfProdNames">'+((window.__SFPROD)||[]).map(function(n){return '<option value="'+esc(n)+'">';}).join('')+'</datalist>';
  var head='<div class="card"><div class="h">Ready-made Kits<button class="btn pri" style="margin-left:auto" onclick="sfSaveKits()">Save kits</button></div>'
    +'<div class="b" style="color:var(--muted);font-size:13px">A kit is a curated bundle a customer adds to a quotation in one tap. Add products by name (each line respects that product\'s MOQ). Assign a kit to a collection, or leave blank to show it in every collection.</div></div>';
  var body=SFKITS.length?SFKITS.map(function(k,ki){return sfKitCard(k,ki);}).join(''):'<div class="card"><div class="b" style="color:var(--muted)">No kits yet. Add one — e.g. a "Restaurant Starter Kit".</div></div>';
  sfEl('view').innerHTML='<div class="wrap">'+sfTabs('kits')+dl+head+'<div style="height:14px"></div>'+body+'<button class="btn" onclick="sfKitAdd()" style="margin-top:12px">+ Add kit</button></div>';
}
function sfKitColOpts(sel){return '<option value="">Every collection</option>'+SFKCOLLS.map(function(c){return '<option value="'+sfj(c.id)+'"'+(sel===c.id?' selected':'')+'>'+esc(c.label)+'</option>';}).join('');}
function sfKitCard(k,ki){
  var lines=(k.lines||[]).map(function(l,li){return sfKitLine(l,ki,li);}).join('');
  var img=k.image_url?('<img class="sfphoto" src="'+esc(k.image_url)+'">'):('<div class="sfphoto">photo</div>');
  return '<div class="card" style="margin-bottom:14px"><div class="h">'
    +'<input class="sfin" value="'+esc(k.label||'')+'" placeholder="Kit name (Restaurant Starter Kit)" style="font-weight:700;max-width:250px" onchange="sfKitUpd('+ki+',\'label\',this.value)">'
    +'<label class="sfchk" style="margin-left:8px"><input type="checkbox" '+(k.active!==false?'checked':'')+' onchange="sfKitUpd('+ki+',\'active\',this.checked)"> Active</label>'
    +'<button class="btn sm danger" style="margin-left:auto" onclick="sfKitDel('+ki+')">Remove</button></div>'
    +'<div class="b">'
      +'<div class="sfrow" style="margin-bottom:10px">'
        +'<div style="text-align:center">'+img+'<div style="margin-top:5px"><button class="btn sm" onclick="sfKitPhoto('+ki+')">Photo</button>'+(k.image_url?' <button class="btn sm danger" onclick="sfKitClearPhoto('+ki+')">✕</button>':'')+'</div></div>'
        +'<div class="sffield" style="flex:1;min-width:180px"><label>Description</label><input class="sfin" value="'+esc(k.desc||'')+'" placeholder="Everything to get started" onchange="sfKitUpd('+ki+',\'desc\',this.value)"></div>'
        +'<div class="sffield"><label>Show in collection</label><select class="sfsel" onchange="sfKitUpd('+ki+',\'collection\',this.value)">'+sfKitColOpts(k.collection||'')+'</select></div>'
        +'<div class="sffield"><label>Button label</label><input class="sfin" style="width:150px" value="'+esc(k.cta_label||'')+'" placeholder="Add kit to quote" onchange="sfKitUpd('+ki+',\'cta_label\',this.value)"></div>'
      +'</div>'
      +'<div style="font-weight:700;font-size:13px;margin:2px 0 8px">Products in this kit</div>'+lines
      +'<button class="btn sm" style="margin-top:4px" onclick="sfKitAddLine('+ki+')">+ Add product</button>'
    +'</div></div>';
}
function sfKitLine(l,ki,li){
  return '<div class="sfrow" style="margin-bottom:6px"><input class="sfin" list="sfProdNames" style="flex:1;min-width:200px" value="'+esc(l.name||'')+'" placeholder="Product name (must match catalogue)" onchange="sfKitLineEd('+ki+','+li+',\'name\',this.value)"><input class="sfin" type="number" min="1" style="width:90px" value="'+(parseInt(l.qty,10)||1)+'" onchange="sfKitLineEd('+ki+','+li+',\'qty\',this.value)"><button class="btn sm danger" onclick="sfKitDelLine('+ki+','+li+')">✕</button></div>';
}
function sfK(ki){return SFKITS[ki];}
function sfKitUpd(ki,k,v){var it=sfK(ki);if(it)it[k]=v;}
function sfKitLineEd(ki,li,k,v){var it=sfK(ki);if(!it||!it.lines||!it.lines[li])return;it.lines[li][k]=(k==='qty')?Math.max(1,parseInt(v,10)||1):v;}
function sfKitAdd(){SFKITS.push({id:'kit'+(SFKITS.length+1)+'_'+Math.random().toString(36).slice(2,6),label:'New Kit',desc:'',image_url:'',active:true,collection:'',cta_label:'Add kit to quote',lines:[{name:'',qty:1}]});sfKitsRender();}
function sfKitDel(ki){if(!confirm('Remove this kit?'))return;SFKITS.splice(ki,1);sfKitsRender();}
function sfKitAddLine(ki){var it=sfK(ki);if(!it)return;it.lines=it.lines||[];it.lines.push({name:'',qty:1});sfKitsRender();}
function sfKitDelLine(ki,li){var it=sfK(ki);if(!it||!it.lines)return;it.lines.splice(li,1);sfKitsRender();}
function sfKitPhoto(ki){_sfPhoto={type:'kit',bi:ki};var f=sfHiddenFile();f.value='';f.click();}
function sfKitClearPhoto(ki){var it=sfK(ki);if(it){it.image_url='';sfKitsRender();}}
async function sfSaveKits(){
  SFKITS.forEach(function(k){k.lines=(k.lines||[]).filter(function(l){return l.name&&String(l.name).trim();});});
  var d=await post('/papi/kits-save',{kits:SFKITS});
  if(d&&d.ok){ if(Array.isArray(d.kits))SFKITS=d.kits; sfKitsRender(); toast('Kits saved'); } else toast((d&&d.error)||'Could not save',true);
}

/* Register the service worker for caching — independent of push permission, so repeat opens are fast
   and the app survives a weak signal even on iOS (where push may be unavailable). Idempotent with the
   push enrolment above (same /sw.js). */
if ('serviceWorker' in navigator) { try { navigator.serviceWorker.register('/sw.js'); } catch (e) {} }

/* ===== Pull-to-refresh (touch) — re-runs the current route. Attaches to the main scroller;
   engages only at the very top, damped, snaps a spinner, then re-fetches the screen. Field-test on device. ===== */
(function(){
  var sc = document.querySelector('main.view');
  var ind = document.getElementById('ptr');
  if(!sc || !ind || !('ontouchstart' in window)) return;
  var startY=0, pulling=false, dist=0, active=false, busy=false;
  var THRESH=64, MAX=96, DAMP=0.5;
  function blocked(){
    var rv=document.getElementById('runview'); if(rv && rv.classList.contains('on')) return true;   // run overlay
    var ov=document.getElementById('ov');      if(ov && ov.classList.contains('on')) return true;   // modal
    var qs=document.getElementById('qsheet');  if(qs && qs.classList.contains('on')) return true;   // create sheet
    var ww=document.getElementById('waweb');   if(ww && ww.classList.contains('open')) return true; // chat
    if(!CUR || !CUR.key) return true;
    // iframe-backed screens have nothing to pull-refresh; leave them to native scroll
    if(CUR.key==='settings'||CUR.key==='payments'||CUR.key==='pnl'||CUR.key==='books'||CUR.key==='partners'||CUR.key==='wa-connect'||CUR.key==='chats') return true;
    return false;
  }
  function setInd(y, spin){
    if(spin){ ind.classList.remove('drag'); ind.classList.add('spin'); ind.style.opacity='1'; ind.style.transform='translateX(-50%) translateY(0)'; return; }
    ind.style.transform='translateX(-50%) translateY('+Math.max(-46, y-46)+'px)';
    ind.style.opacity=String(Math.min(1, y/THRESH));
  }
  function reset(){ ind.classList.remove('spin'); ind.classList.remove('drag'); ind.style.opacity=''; ind.style.transform=''; pulling=false; active=false; dist=0; }
  sc.addEventListener('touchstart', function(e){
    if(busy) return;
    if(sc.scrollTop>0 || blocked() || e.touches.length!==1){ active=false; return; }
    startY=e.touches[0].clientY; active=true; pulling=false; dist=0;
  }, {passive:true});
  sc.addEventListener('touchmove', function(e){
    if(!active || busy) return;
    var dy=e.touches[0].clientY - startY;
    if(dy<=0){ if(!pulling) active=false; return; }
    if(sc.scrollTop>0){ active=false; reset(); return; }
    if(!pulling && dy<6) return;              // let small vertical taps/flicks scroll normally
    pulling=true; ind.classList.add('drag');
    e.preventDefault();                       // own the gesture (needs passive:false) — suppresses native overscroll
    dist=Math.min(MAX, dy*DAMP);
    setInd(dist, false);
  }, {passive:false});
  function end(){
    if(!active) return;
    if(pulling && dist>=THRESH && !busy){
      busy=true; setInd(0, true);
      var done=function(){ busy=false; reset(); };
      var minWait=new Promise(function(r){ setTimeout(r, 520); });   // hold the spinner long enough to read as a refresh
      var run; try{ run=Promise.resolve(route(CUR.key, CUR.id)); }catch(err){ run=Promise.resolve(); }
      Promise.all([run, minWait]).then(done, done);
    } else { reset(); }
  }
  sc.addEventListener('touchend', end, {passive:true});
  sc.addEventListener('touchcancel', function(){ if(!busy) reset(); active=false; }, {passive:true});
})();

boot();

/* v4.42 — purchase line rows. Kept as plain globals so the inline onclick/oninput in the row
   markup can reach them, matching how every other field type in this form works. */
function lnAdd(k){
  const tb=document.getElementById('lnb_'+k); if(!tb) return;
  const tr=document.createElement('tr');
  tr.innerHTML='<td style="padding:3px 6px"><input class="ln-p" list="dl_'+k+'" autocomplete="off" placeholder="Type or choose\u2026" oninput="lnCalc(\''+k+'\')" style="width:100%;font-size:13px;padding:6px 8px;border:1px solid var(--line,#e4e9e4);border-radius:7px"></td>'+
    '<td style="padding:3px 6px"><input class="ln-q" type="number" step="1" min="0" oninput="lnCalc(\''+k+'\')" style="width:100%;font-size:13px;padding:6px 8px;border:1px solid var(--line,#e4e9e4);border-radius:7px"></td>'+
    '<td style="padding:3px 6px"><input class="ln-u" type="number" step="0.01" min="0" oninput="lnCalc(\''+k+'\')" style="width:100%;font-size:13px;padding:6px 8px;border:1px solid var(--line,#e4e9e4);border-radius:7px"></td>'+
    '<td class="ln-s" style="padding:3px 6px;text-align:right;color:var(--muted,#6b7280)">\u2014</td>'+
    '<td style="padding:3px 2px"><button type="button" title="Remove this row" onclick="lnDel(this,\''+k+'\')" style="border:1px solid #fecaca;background:#fff;color:#b91c1c;border-radius:6px;width:24px;height:24px;cursor:pointer;font-weight:800;line-height:1">\u00d7</button></td>';
  tb.appendChild(tr);
  const f=tr.querySelector('.ln-p'); if(f) f.focus();
  lnCalc(k);
}
function lnDel(btn,k){
  const tb=document.getElementById('lnb_'+k); if(!tb) return;
  // never leave the table with nothing to type into
  if(tb.querySelectorAll('tr').length<=1){ ['.ln-p','.ln-q','.ln-u'].forEach(s=>{ const e=btn.closest('tr').querySelector(s); if(e) e.value=''; }); }
  else { btn.closest('tr').remove(); }
  lnCalc(k);
}
function lnCalc(k){
  const tb=document.getElementById('lnb_'+k); if(!tb) return;
  let total=0, n=0;
  tb.querySelectorAll('tr').forEach(tr=>{
    const nm=((tr.querySelector('.ln-p')||{}).value||'').trim();
    const q=parseFloat((tr.querySelector('.ln-q')||{}).value)||0;
    const u=parseFloat((tr.querySelector('.ln-u')||{}).value)||0;
    const sum=q*u; const cell=tr.querySelector('.ln-s');
    if(cell) cell.textContent = (nm!=='' && q>0) ? sum.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2}) : '\u2014';
    if(nm!=='' && q>0){ total+=sum; n++; }
  });
  const t=document.getElementById('lntot_'+k);
  if(t) t.textContent = n ? (n+' item'+(n>1?'s':'')+' \u00b7 '+total.toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})) : '\u2014';
  // keep the bill amount in step with the rows, so nobody types a total that disagrees with them
  const amt=document.getElementById('f_native_amount');
  if(amt && n) amt.value = Math.round(total*100)/100;
}
