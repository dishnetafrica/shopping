#!/usr/bin/env python3
"""
Boxer 100 parts database builder — Batch 1 (Engine + Transmission, plates 1-11).

Source: BM 100 ES & KS Spare Parts Catalogue (Brand ID 001MC643), verbatim.
Prices are STARTER RETAIL ESTIMATES in UGX (clearly flagged) — set your real
cost/retail before go-live. Qty is per-vehicle (from the catalogue), not stock.

Emits:
  data/diagrams/boxer100.json   — diagram-page data (plates + part lists)
  parts-import.csv              — ProductImporter-ready bulk import
"""
import json, csv, pathlib
ROOT = pathlib.Path(__file__).resolve().parent.parent
DIA = ROOT / "assets/diagrams/boxer100"

# (plate, name, system, page, diagram_filename, [ (no, partNo, desc, ksQty, esQty, price, remarks) ])
PLATES = [
 (1,"Cover Cylinder Head","Engine",2,"plate-01-cover-cylinder-head.png",[
   ("1","JZ 5110 21","Cover Cylinder Head Assly",1,1,45000,""),
   ("2","JV 5410 07","Bolt Flange",4,4,2000,""),
   ("3","JD 5110 10","Gasket Head Cover",1,1,3000,""),
   ("4","JB 5110 11","Bolt Head Mounting",4,4,1500,"M8 X 1.25 X 190L"),
   ("5","JZ 5110 20","Plate Cylinder Head",1,1,6000,""),
   ("6","JA 5110 11","Hose Breather",1,1,2500,""),
   ("7","DP 1210 25","Clamp (ID 12.5 MM)",3,3,800,""),
   ("8","JB 5110 15","Flange Bolt",1,1,1200,""),
 ]),
 (2,"Cylinder Head","Engine",4,"plate-02-cylinder-head.png",[
   ("1","36 PF00 37","Kit Assly. Cylinder Head",1,1,185000,"Ref. Page No."),
   ("2","JA 5110 28","Stud",2,2,1500,"M8 X 25 Silver"),
   ("3","JB 5110 19","Gasket Cylinder Head",1,1,7500,""),
   ("4","PF 5814 00","Intake Manifold",1,1,14000,""),
   ("5","JA 5814 06","Bolt Flange",2,2,2000,""),
   ("6","39 1283 21","'O' Ring (Intake Pipe)",1,1,1200,""),
   ("7","JB 5812 71","Insulator PTE-16",1,1,3500,""),
   ("8","JB 5812 72","'O' Ring Insulator",1,1,1000,""),
 ]),
 (3,"Valve","Engine",6,"plate-03-valve.png",[
   ("1","JA 5112 11","Shaft Rocker Arm",2,2,9000,"48.2 mm"),
   ("2","JA 5112 04","Rocker Arm with Roller",2,2,16000,""),
   ("3","DG 1010 29","Cotter Valve (Collet)",4,4,600,""),
   ("4","36 PA00 04","Valve Kit",1,1,28000,"Refer Page No. 92"),
   ("5","36 JA00 03","Valve Repair Kit",1,1,35000,"Refer Page No. 91"),
   ("6","39 2017 19","Seal Valve Stem",2,2,2500,""),
 ]),
 (4,"Camshaft / Tensioner","Engine",8,"plate-04-camshaft-tensioner.png",[
   ("1","PF 5112 00","Assly. Camshaft Complete",1,1,55000,""),
   ("2","JA 5112 16","Spacer Cam Sprocket",1,1,2500,""),
   ("3","DS 1012 76","Sprocket Cam Shaft - 32 T",1,1,12000,""),
   ("4","DH 1013 87","Hex Head Bolt",1,1,800,""),
   ("5","DH 1010 48","Special Washer Cam Shaft",1,1,600,"'O' Washer"),
   ("6","36 PA00 02","Guide Chain Kit",1,1,14000,"Ref. Page No. 91"),
   ("7","DH 1013 92","Cam chain Tensioner Pivot Bolt",1,1,1500,""),
   ("8","JV 5210 03","Adjuster Chain Silver",1,1,9000,""),
   ("9","CS 1011 28","Gasket",1,1,700,""),
   ("10","JA 5210 18","Bolt M6 Chain Tensioner",1,1,800,""),
   ("11","JA 5210 19","Bolt M6 X 20 Silver",2,2,700,"Adjuster Mounting"),
   ("12","39 0594 17","Circlip for Camshaft",1,1,500,""),
   ("13","39 2669 21","'O' Ring (Tensioner)",1,1,600,""),
   ("14","JA 5310 11","Cam Chain (94 Link)",1,1,22000,""),
   ("15","39 1988 20","Bearing Ball - Small 12 X 28 X 8",1,1,8000,"W X OD X ID"),
   ("16","39 2683 20","Bearing Ball - Big 17 X 35 X 10",1,1,12000,""),
 ]),
 (5,"Cylinder and Piston","Engine",10,"plate-05-cylinder-piston.png",[
   ("1","JD 5210 02","Gasket Cylinder Block",1,1,6000,""),
   ("2","39 1000 06","Dowel Pin",2,2,500,""),
   ("3","36 PF00 28","Piston Ring Set",1,1,22000,"Refer Page No. 92"),
   ("4","JB 5212 11","Wire Clip Piston",2,2,600,""),
   ("5","36 PF00 27","Kit Cylinder Block / Piston Assly.",1,1,145000,"Refer Page No. 92"),
 ]),
 (6,"Crankshaft","Engine",12,"plate-06-crankshaft.png",[
   ("1","59 1500 14","Flange Nut",2,2,1500,""),
   ("2A","59 1200 03","Belleville Washer",2,2,800,""),
   ("2B","59 1100 08","Plain Washer",1,1,500,""),
   ("3","31 1011 73","Key Woodruff",1,1,500,""),
   ("4","PF 5310 02","Crank Shaft Assly",1,1,165000,""),
   ("5","39 1864 20","Ball Bearing 20 X 52 X 15",2,2,14000,""),
   ("6","DS 1011 54","Parallel Key",1,1,500,""),
   ("7","36 JZ00 23","Set Primary Drive / Driven",1,1,48000,"Refer Page No. 91"),
   ("8","JA 5310 12","Spring",1,1,900,""),
   ("9","JA 5310 15","Pin Crank Shaft Plunger",1,1,800,""),
   ("10","JB 5310 21","Plunger Oil Crank Shaft",1,1,1200,""),
 ]),
 (7,"Transmission","Transmission",14,"plate-07-transmission.png",[
   ("1","PF 5510 02","Shaft Input Transmission (Drive)",1,1,42000,""),
   ("2","PF 5512 02","Gear 4th Drive",1,1,16000,""),
   ("3","39 0987 17","Ring Snap (17.2 X 20.5 X 1.5)",5,5,400,""),
   ("4","JA 5510 02","Spline Washer (17.2 X 20.5 X 1.5)",3,3,500,""),
   ("5","PF 5512 01","Gear 3rd Drive",1,1,16000,""),
   ("6","PF 5512 00","Gear 2nd Drive",1,1,16000,""),
   ("7","JA 5510 04","Washer Thrust 2nd Drive",1,1,600,""),
   ("8","PF 5510 03","Shaft Output Transmission (Driven)",1,1,42000,""),
   ("9","PF 5512 06","2nd Driven Washer",1,1,600,""),
   ("10","PF 5512 03","Gear 2nd Driven",1,1,17000,""),
   ("11","PF 5512 04","Gear 3rd Driven",1,1,17000,""),
   ("12","PF 5512 05","Gear 4th Driven",1,1,17000,""),
   ("13","JZ 5512 05","Bush 1st Driven",1,1,1500,""),
   ("14","JZ 5512 04","Gear 1st Driven",1,1,18000,""),
   ("15","JA 5510 08","Washer Thrust 1st Driven",1,1,600,""),
   ("16","JA 5510 09","Collar Sprocket Drive",1,1,2500,""),
   ("17","59 2100 24","'O' Ring Collar Sprocket (13.8 X 1.9)",1,1,500,""),
   ("18","JZ 5510 04","Sprocket Drive (Engine Sprocket)",1,1,12000,"14 Teeth"),
   ("19","DU 1012 13","Plate Lock Sprocket",1,1,800,""),
   ("20","39 1981 04","Socket Head Bolt",2,2,600,""),
 ]),
 (8,"Change Drum / Shift Fork","Transmission",16,"plate-08-change-drum-shift-fork.png",[
   ("1","PF 5610 00","Drum Complete Gear Shift",1,1,28000,""),
   ("2","39 0992 06","Dowel (Pin Drum Change)",5,5,500,""),
   ("3","JA 5610 02","Cam Drum Change",1,1,6000,""),
   ("4","JD 5610 11","Special Bolt Drum Change",1,1,800,"M6 X 1"),
   ("5","PF 5610 02","Shaft Gear Shift Fork",2,2,4000,""),
   ("6","JD 5610 04","Fork Gear Shift Output",1,1,9000,""),
   ("7","JZ 5610 02","Fork Gear Shift Input",1,1,9000,""),
 ]),
 (9,"Gear Change Mechanism","Transmission",18,"plate-09-gear-change-mechanism.png",[
   ("1","PF 5612 00","Lever Gear Shift Comp",1,1,14000,""),
   ("2","JW 5612 06","Extension Spring",1,1,800,""),
   ("3","JW 5612 08","Spring Gear Shift Return",1,1,900,""),
   ("4","JA 5612 07","Inhibitor Complete Gear Shift",1,1,6000,""),
   ("5","JA 5612 08","Spring Inhibitor",1,1,700,""),
   ("6","JA 5612 09","Step Washer (Collar)",1,1,700,""),
   ("7","39 2704 11","Plain Washer",1,1,400,""),
   ("8","39 0817 15","Hex Nut / Flange - M6",1,1,400,""),
   ("9","JZ 5614 01","Lever Gear Shift (Gear Pedal)",1,1,12000,""),
   ("10","DH 1015 09","Damper",1,1,1200,""),
   ("11","DG 1013 39","Damper",1,1,1200,""),
   ("12","JA 5612 11","Flange Bolt",1,1,700,""),
 ]),
 (10,"Engine Assembly / Crankcase RH","Engine",20,"plate-10-engine-assly-crankcase-rh.png",[
   ("1","PF 5010 06","Engine Assly KS 4 Speed",1,0,1100000,"N.I. — KS variant"),
   ("1","PF 5010 05","Engine Assly ES 4 Speed",0,1,1150000,"N.I. — ES variant"),
   ("2","36 PF00 40","Kit Crankcase RH",1,1,95000,""),
   ("3","59 2000 36","6203 Ball Bearing",1,1,6000,"For I/P Shaft"),
   ("4","JA 5410 11","Needle Roller Bearing",1,1,8000,"For O/P Shaft"),
   ("5","JA 5410 10","Stopper Plate",1,1,1500,""),
   ("6","39 1000 06","Dowel Pin",2,2,500,""),
   ("7","PF 5410 18","Bushing Drum BM-150",1,1,2500,""),
   ("8","DH 1013 80","Screw",1,1,400,""),
   ("9","52 JA00 27","Stud Inhibitor Mounting",1,1,900,""),
   ("10","JV 5412 16","Bolt M6 X 85 Silver",2,2,900,"Crankcase Mounting"),
   ("11","JV 5410 07","Flange Bolt",1,1,700,"Crankcase Mounting"),
   ("12","52 JA00 29","Bolt Shift Change",1,1,900,""),
   ("13","JA 5410 12","Guide Kick",1,1,2000,""),
   ("14","39 1964 04","Flange Bolt - Small",2,2,600,""),
 ]),
 (11,"Crankcase LH","Engine",22,"plate-11-crankcase-lh.png",[
   ("1","36 PF00 39","Kit Crankcase Magneto Side KS",1,0,95000,"KS variant"),
   ("1","36 PF00 38","Kit Crankcase Magneto Side ES",0,1,98000,"ES variant"),
   ("2","39 2562 20","Needle Roller Bearing",1,1,8000,""),
   ("3","59 2000 36","6203 Ball Bearing",1,1,6000,""),
   ("4","39 1715 19","Seal Oil",1,1,3000,"Output Shaft"),
   ("5","31 2010 81","Switch Complete",1,1,12000,"Neutral Switch"),
   ("6","DH 1017 84","Gasket Neutral Switch (T.I.S)",1,1,600,""),
   ("7","39 2190 19","Oil Seal",1,1,2500,"Shifter Shaft"),
   ("8","JA 5410 05","Bush Bendix",0,1,2000,""),
   ("9","39 0747 01","Screw Pan Cross M6",0,2,400,""),
   ("10","JA 5410 34","Stopper Gear Starter Aluminium",0,1,2000,""),
   ("11","39 1000 06","Pin Dowel",4,4,500,""),
   ("12","39 0999 06","Hollow Dowel Pin",4,4,500,""),
   ("13","JG 5710 24","Mesh Oil Filter",1,1,4000,""),
   ("14","LJ A000 04","'O' Ring Strainer",1,1,600,""),
   ("15","JV 5410 06","Cap Strainer",1,1,2000,""),
   ("16","JV 5410 07","Flange - Bolt",6,6,700,"Crankcase Mounting"),
   ("17","39 1759 04","Bolt (M6 X 1 X 55L)",1,1,700,""),
 ]),
]

SYS_ID = {"Engine":"engine","Transmission":"transmission","Fuel System":"fuel_system","Electrical":"electrical"}

PLATES += [
 (12,"Clutch Cover","Transmission",24,"plate-12-clutch-cover.png",[
   ("1","36 PF00 08","Kit Cover Clutch Assly. Complete",1,1,65000,""),
   ("2","JK 5412 14","Decal",1,1,1500,""),
   ("3","39 1041 19","Oil Seal Clutch Lever",1,1,2500,""),
   ("4","30 1011 42","Oil Seal (Kick Shaft)",1,1,2500,""),
   ("5","JV 5412 08","Shaft Clutch Release Assly",1,1,6000,""),
   ("6","DP 1012 69","Rod Push",1,1,3000,""),
   ("7","DG 1014 18","Gauge",1,1,4000,"Oil Level Check"),
   ("8","DS 1012 15","Wire Circlip",1,1,400,""),
   ("9","JA 5412 36","Plain Washer",1,1,400,""),
   ("10","JA 5412 37","Spring Torsion Clutch Lever",1,1,800,""),
   ("11","JA 5412 11","Cap Oil Filler",1,1,2000,""),
   ("12","JA 5412 25","'O' Ring Cap Oil Filler",1,1,500,""),
   ("13","JA 5412 12","Gasket Clutch Cover",1,1,4000,""),
   ("14","JV 5412 15","Bolt M6X40 Silver Color",8,8,700,""),
   ("15","JV 5412 09","Bracket Clutch Cable",1,1,2500,""),
   ("16","DD 1211 81","Oil Filter Element",1,1,6000,""),
   ("17","39 1671 21","'O' Ring",1,1,500,""),
   ("18","JV 5412 05","Cap Filter",1,1,2500,""),
   ("19","JA 5210 19","Bolt Flange",3,3,700,""),
   ("20","39 0999 06","Pin Hollow Dowel",2,2,500,""),
 ]),
 (13,"Cover Magneto","Engine",26,"plate-13-cover-magneto.png",[
   ("1","PF 5412 11","Cover Magneto KS",1,0,42000,"KS variant"),
   ("1","36 PF00 07","Kit Cover Magneto Assly ES",0,1,58000,"ES variant"),
   ("2","JV 5412 15","Bolt M8 Silver colour",8,8,700,""),
   ("3","JP 5412 08","Gasket Magneto Cover KS",1,0,4000,"KS variant"),
   ("3","JA 5412 05","Gasket Magneto Cover ES",0,1,4000,"ES variant"),
   ("4","JV 5410 07","Bolt",2,2,700,""),
   ("5","JV 5412 06","Cover LH Rear Complete (KS)",1,0,12000,"KS variant"),
   ("5","JV 5412 12","Cover LH Rear Complete (ES)",0,1,12000,"ES variant"),
   ("6","JA 5410 05","Bush",0,1,2000,"For Drive Gear Assly"),
   ("7","JA 5412 33","Cap TDC Finder",1,1,1500,""),
   ("8","30 1011 95","'O' Ring",1,1,500,""),
   ("9","DS 1611 50","Sticker Gear Shifting",1,1,800,""),
   ("10","JK 5412 14","Decal Magneto Cover",1,1,1500,""),
 ]),
 (14,"Clutch Assembly","Transmission",28,"plate-14-clutch-assembly.png",[
   ("1","JZ 5514 13","Clutch Assembly Complete",1,1,95000,""),
   ("2","JA 5514 06","Collar Clutch",1,1,2500,""),
   ("3","39 2600 11","Washer",1,1,500,""),
   ("4","36 JZ00 37","Kit Plate Friction",1,1,22000,"Refer Page No. 91"),
   ("5","JB 5514 08","Wheel Clutch",1,1,28000,""),
   ("6","30 1010 96","Plate Clutch (Steel Plate)",4,4,3500,""),
   ("7","JB 5514 11","Hub Clutch (Modified)",1,1,24000,""),
   ("8","JZ 5514 15","Spring Clutch",6,6,900,""),
   ("9","JB 5514 06","Holder Clutch (Modified)",1,1,18000,""),
   ("10","39 0980 04","Bolt Hexagon Head",6,6,600,""),
   ("11","39 1057 11","Belleville Washer",1,1,700,""),
   ("12","JA 5514 20","Plain Washer",1,1,400,""),
   ("13","52 JA00 28","Nut Special M14",1,1,1500,""),
   ("14","39 1001 20","Bearing Ball",1,1,6000,""),
   ("15","36 JZ00 23","Set Primary Drive & Driven",1,1,48000,"Refer Page No. 91"),
 ]),
 (15,"Kick Starter Mechanism","Transmission",30,"plate-15-kick-starter-mechanism.png",[
   ("1","JA 6212 01","Lever Assly Kick",1,1,14000,""),
   ("2","JA 6212 06","Bolt Flange",1,1,700,""),
   ("3","36 DU10 07","Boss",1,1,3000,"Refer Page No. 91"),
   ("4","22 1502 41","Plain Washer",1,1,400,""),
   ("5","39 0993 11","Plain Washer Small",3,3,400,""),
   ("6","39 0738 17","Circlip Shaft",2,2,400,""),
   ("7","JA 6210 01","Shaft Kick",1,1,12000,""),
   ("8","DS 1011 17","Spring Kick Starter Ratchet",1,1,900,""),
   ("9","JA 6210 02","Ratchet",1,1,6000,""),
   ("10","JZ 6210 00","Gear Kick Pinion",1,1,12000,""),
   ("11","JA 6210 06","Washer Thrust (16.2 X 25 X 0.5)",1,1,500,""),
   ("12","39 2408 17","Circlip",1,1,400,""),
   ("13","DD 1010 97","Kick Rubber",1,1,2500,""),
   ("14","JE 5410 08","Spring Kick Starter Return",1,1,1500,""),
 ]),
 (16,"Carburettor","Fuel System",32,"plate-16-carburettor.png",[
   ("1","PF 5813 00","Carburettor assly.",1,1,78000,""),
   ("2","JB 5812 37","Adjuster throttle cable",1,1,1500,""),
   ("3","JB 5812 38","Nut adjuster",1,1,500,""),
   ("4","36 PF00 33","Air screw Kit",1,1,3500,"Refer Page No. 93"),
   ("5","36 PF00 32","Kit Jet Needle",1,1,4500,"Refer Page No. 93"),
   ("6","36 PA00 13","Idling screw kit",1,1,3000,"Refer Page No. 92"),
   ("7","JB 5812 29","Valve complete float (Float pin)",1,1,6000,""),
   ("8","JB 5812 30","Float complete",1,1,5000,""),
   ("9","JB 5812 32","Screw washers M4x14",1,1,400,""),
   ("10","JB 5812 02","Throttle position sensor",1,1,22000,""),
   ("11","JB 5812 04","'O' Ring (TPS)",1,1,600,""),
   ("12","PF 5813 08","Tube",1,1,1500,""),
   ("13","JB 5812 59","Clip",1,1,400,""),
   ("14","36 PF00 34","Throttle Value Kit",1,1,6000,"Refer Page No. 93"),
   ("15","36 PA00 15","Carb. Gasket 'O' ring",1,1,3000,"N.I. Refer Page No. 92"),
   ("16","JB 5812 56","Arm Pin Float",1,1,1500,""),
   ("17","JB 5812 60","Drain Screw",1,1,800,""),
   ("18","PF 5813 09","Lever Complete Starter",1,1,4000,""),
 ]),
 (17,"Air Cleaner Complete","Fuel System",34,"plate-17-air-cleaner-complete.png",[
   ("1","PF 5810 00","Assembly Air Filter",1,1,35000,""),
   ("2","JB 5810 01","Duct Air Intake",1,1,6000,""),
   ("3","JA 5810 05","Assly Catridge Air filter",1,1,9000,""),
   ("4","JA 5810 06","Element Air Filter",1,1,9500,""),
   ("5","JA 5810 09","Flame Arrester",1,1,3000,""),
   ("6","JA 5810 12","Tube Drain",1,1,1500,""),
   ("7","DS 1211 26","Plug",1,1,400,""),
   ("8","DP 1210 25","Clamp",1,1,800,""),
   ("9","JA 5110 11","Hose Breather",1,1,2500,""),
   ("10","JB 5810 01","Air Intake Duct",1,1,6000,""),
   ("11","JA 5810 13","Cartridge Cover Assly",1,1,8000,""),
   ("12","JA 5810 11","'O' Ring Cartridge Cover",1,1,600,""),
   ("13","39 1773 04","Flange Bolt",4,4,600,""),
   ("14","DH 1210 38","Clamp",1,1,800,""),
   ("15","DF 1614 26","Bolt - Stepped with Flange",2,2,700,""),
 ]),
 (18,"Silencer Assembly","Engine",36,"plate-18-silencer-assembly.png",[
   ("1","PF 5910 00","Assembly Silencer",1,1,62000,""),
   ("2","CB 1011 14","Gasket Exhaust Mounting",1,1,1500,""),
   ("3","39 2295 15","Flange Nut",2,2,600,""),
   ("4","JB 2312 06","Bolt Hex. Socket Head",1,1,700,""),
 ]),
 (19,"Oil Pump","Engine",38,"plate-19-oil-pump.png",[
   ("1","PF 5710 00","Oil Pump Assly",1,1,32000,""),
   ("2","JA 5410 16","Gasket Case Oil Pump",1,1,1500,""),
   ("3","FD 1010 78","Pan Head Screw M6 X 1 X 3DL",3,3,400,""),
   ("4","39 0999 06","Dowel",2,2,500,""),
   ("5","PF 5710 01","Gear Oil Pump",1,1,8000,""),
 ]),
 (20,"Starter Assembly","Electrical",40,"plate-20-starter-assembly.png",[
   ("1","JA 6214 14","Body comp. starter clutch",0,1,45000,"ES only"),
   ("2","DH 1017 81","Plate roller clutch",0,1,6000,"ES only"),
   ("3","JA 6214 10","Gear starter clutch",0,1,18000,"ES only"),
   ("4","JA 6214 02","Washer",0,2,500,"ES only"),
   ("5","39 2437 04","Bolt socket",0,3,600,"ES only"),
   ("6","JA 6214 06","Starter drive assly.",0,1,22000,"ES only"),
   ("7","JV 3516 00","Motor Assly",0,1,120000,"ES only"),
   ("8","JA 3516 04","'O' Ring For Tie Bolt (Flash)",0,1,500,"ES only"),
   ("9","JA 3516 06","Sealing Ring (Flash)",0,1,500,"ES only"),
   ("9","JA 3516 26","Sealing Ring (Lucas-TVS)",0,1,500,"ES only"),
   ("10","DH 1011 63","Flange Bolt (Flash)",0,2,600,"ES only"),
   ("11","JA 3516 02","'O' Ring For Spigot (Flash)",0,1,500,"ES only"),
   ("11","JA 3516 27","'O' Ring For Spigot (Lucas-TVS)",0,1,500,"ES only"),
   ("12","JA 3516 08","+ve Brush Holder Assly (Flash)",0,1,8000,"ES only"),
   ("13","JA 3516 09","-ve Brush Holder Assly (Flash)",0,1,8000,"ES only"),
   ("14","JA 3516 18","Brush & Earth Plate Assly",0,1,9000,"N.I. (Lucas-TVS)"),
   ("15","JA 3516 24","Brush Spring (Lucas-TVS)",0,1,800,"N.I."),
   ("16","JA 3516 19","Terminal Assly (Lucas-TVS)",0,1,6000,"ES only"),
   ("17","36 JW0014","Starter Clutch Repair Kit",0,1,18000,"Refer Page No. 91"),
 ]),
 (21,"Magneto, CDI & H.T. Coil","Electrical",42,"plate-21-magneto-cdi-ht-coil.png",[
   ("1","PF 3510 07","Rotor Assly (KS)",1,0,65000,"KS variant"),
   ("1","PF 3510 05","Rotor Assly (ES)",0,1,68000,"ES variant"),
   ("2","PF 3510 04","Stater Assly Complete1 (KS)",1,0,55000,"KS variant"),
   ("2","PF 3510 06","Stater Assly Complete (ES)",0,1,58000,"ES variant"),
   ("3","39 1674 04","Bolt Flange M6 X 28",2,2,700,""),
   ("4","DH 1013 80","Pan Head Screw M6 X 12",2,3,400,"Lock Plate & Pick Up Coil Mounting"),
   ("5","JV 3512 04","H. T. Coil With Cable",1,1,16000,""),
   ("6","PF 3512 00","CDI AC STEP UP",1,1,45000,""),
   ("7","DD 1110 18","Spark Plug PRZ9 HC / UR4AC",1,1,8000,""),
   ("8","DD 1110 48","Spark Plug Cap LH",1,1,3000,""),
   ("9","DD 1110 11","Grommet Spark Plug Cap LH",1,1,600,""),
   ("10","DD 1110 12","Grommet for HT Cable",1,1,600,""),
   ("11","39 1943 04","Bolt Flange M6",2,2,500,""),
   ("12","JN 4020 00","Regulator With Capacitor",1,1,28000,""),
   ("13","JA 5410 28","Lock Plate",0,1,1500,"ES only"),
 ]),
]


def build_json():
    plates = []
    for plate, name, system, page, dfile, parts in PLATES:
        prows = [{"no": no, "partNo": pn, "description": d,
                  "qty": {"KS": ks, "ES": es}, "price": price, "remarks": rmk}
                 for (no, pn, d, ks, es, price, rmk) in parts]
        plates.append({"plate": plate, "name": name, "system": SYS_ID[system],
                       "page": page, "diagram": f"assets/diagrams/boxer100/{dfile}",
                       "parts": prows})
    doc = {"meta": {"model": "boxer100", "brandId": "001MC643",
                    "description": "Boxer 100 (ES/KS) exploded-diagram plates + verbatim part lists. Batches 1-2: Engine, Transmission, Fuel, Electrical, Starting (plates 1-21). Prices are starter UGX retail estimates.",
                    "source": "BM 100 ES & KS SPC_18052012.pdf",
                    "coverage": "plates 1-21 of 45 (Batches 1-2)"},
           "plates": plates}
    (ROOT / "data/diagrams/boxer100.json").write_text(json.dumps(doc, indent=2, ensure_ascii=False), encoding="utf-8")
    return sum(len(p) for *_, p in PLATES)


def build_csv():
    rows = []
    for plate, name, system, page, dfile, parts in PLATES:
        for (no, pn, d, ks, es, price, rmk) in parts:
            sku = pn.replace(" ", "")
            kw = f"Boxer 100 | {system} | {name} | {pn} | plate {plate}"
            if rmk:
                kw += f" | {rmk}"
            rows.append({
                "Name": d,
                "SKU": sku,
                "Category": f"Boxer 100 > {system} > {name}",
                "Description": f"Genuine Bajaj part — {d}. Fits Boxer 100 (KS/ES). Part No {pn}. Plate {plate}, page {page}." + (f" {rmk}." if rmk else ""),
                "Price": price,
                "Base Price": "",
                "Stock": "",
                "MOQ": 1,
                "Unit": "Each",
                "Image": "",
                "Keywords": kw,
                "Active": 1,
            })
    cols = ["Name","SKU","Category","Description","Price","Base Price","Stock","MOQ","Unit","Image","Keywords","Active"]
    with open(ROOT / "parts-import.csv", "w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(f, fieldnames=cols)
        w.writeheader()
        w.writerows(rows)
    return len(rows)


if __name__ == "__main__":
    n1 = build_json()
    n2 = build_csv()
    print(f"plates: {len(PLATES)}  parts(json): {n1}  csv rows: {n2}")
