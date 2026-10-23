<?php

/*
| Copy shown in the document picker. Keyed by ingestion profile (see config/rag.php):
| each profile corresponds to one of the three use cases in RAG-Hands-On-Use-Cases.docx.
*/
return [
    'narrative' => [
        'use_case' => 'Use case 1 - plain-text business report',
        'blurb' => 'Narrative text with numbers woven into sentences. Answers must be the exact fact from its paragraph.',
        'questions' => [
            'How many depository libraries did GPO staff visit in FY 2018?',
            'What new digital system replaced FDsys, and when was it fully deployed?',
            'How much did GPO procure in printing and information products in FY 2018 on behalf of federal agencies?',
        ],
    ],
    'structured' => [
        'use_case' => 'Use case 2 - long, structured report',
        'blurb' => 'Chapters, regions, boxes and tables. Questions are routed to the right section so regions never get mixed up.',
        'questions' => [
            'What is the growth outlook for South Asia in this report?',
            'What does Chapter 3 say about sovereign debt in developing economies?',
            'According to the executive summary, what are the main risks to the global outlook?',
        ],
    ],
    'visual' => [
        'use_case' => 'Use case 3 - short visual document',
        'blurb' => 'Facts sit in charts, small tables, icon call-outs and footnotes, which are rebuilt into searchable text.',
        'questions' => [
            'How much less energy does iMac use compared to the ENERGY STAR requirement?',
            'What is the total carbon footprint of the iMac, and how much of that comes from product use versus manufacturing?',
            'What material is the iMac stand made from, and what percentage of it is recycled?',
        ],
    ],
];
