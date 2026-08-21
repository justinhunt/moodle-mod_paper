# Paper Module (mod_paper)

Poodll Paper is a Moodle activity module that allows the teacher to distribute paper assignments, which are later scanned, evaluated by AI, and the results printed to paper for return to students. It allows teachers to use AI to speed up manual grading and does not require students to use a computer or log in to Moodle.

## Features

- **Custom Template Designer**: Define specific response areas on a blank PDF worksheet.
- **AI-Powered OCR**: Automatically extracts student handwriting from scanned documents.
- **Grammar Correction**: Integrates with LLMs to provide automated feedback and grammar correction.
- **Manual Grading Sidebar**: Easily review student work, adjust grades, and provide specific feedback.
- **"Display Only" Snippets**: Retain worksheet elements that are necessary but do not need OCR (e.g. for multiple choice questions or drawings).
- **PDF Evaluation Reports**: Generate high-quality PDF reports for students showing their original work with digital overlays.
- **Gradebook Integration**: Automatically syncs total scores back to the Moodle gradebook. (optional)

## Workflow

1. **Setup**: Upload a blank worksheet ( PDF or JPG) to the activity. Mark up the response areas on the worksheet by dragging your mouse over them, and setting grading, maximum score and other criteria.
2. **Scan**: Collect and scan the completed student worksheets as a single multipage PDF, or as multuple PDFs.
3. **Process**: Upload the scanned PDF(s) to the activity. The system will assume one page per student submission and perform OCR. It will then perform an evaluation. This will take place during the Moodle cron and may take a minute or two.
4. **Evaluate**: Review the results in the Evaluation Report. Use the interactive sidebar to verify AI corrections and grades, and optionally adjust them.
5. **Report**: Print combined PDF report and return evaluations to students.

## Technical Requirements

- **Ghostscript**: Required for PDF to Image conversion during processing.
- **Open AI API Key**: For OCR and evaluation.

## A Video Walkthrough

See a walkthrough of setting up a Poodll Paper activity here:

[https://youtu.be/ivEStVh2Yrs](https://youtu.be/ivEStVh2Yrs)

## Installation

1. Copy the `paper` directory to your Moodle's `/mod/` folder.
2. Log in as an administrator and go to `Site Administration > Notifications` to complete the installation.
3. Be sure to set your OpenAI API key in `Site Administration > Plugins > Activity modules > Poodll Paper`.

## Try it

There is a testworksheet.pdf and testworksheet_submissions.pdf in the samples folder. You can test with those.

NB At the moment the worksheet must be A4 size and portrait orientation.

There is a test site at: [https://paper.poodll.com](https://paper.poodll.com).
Contact Poodll to get access: [https://poodll.com/contact](https://poodll.com/contact).

## Permissions

- `mod/paper:addinstance`: Allow creating new Paper activities.
- `mod/paper:submit`: Allow students to view their evaluations.
- `mod/paper:manage`: Allow teachers to setup templates, process scans, and grade work.

## Tips

- When designing your worksheet, make sure that there is adequate spacing between response areas. Even when scanning with a sheet feeder each paper is not always at the same position on the scanner. So Poodll Paper takes a wide margin (configurable and defaults to: 5mm ) around each area before performing OCR.

- Provide boxes for students to write in on the worksheet and instruct them to write clearly and within the box borders. This helps prevent students writing outside the area delineated for OCR to process

- Leave some space on the right of graded response areas for the grade to be printed. 

- If you want AI to give feedback/comments create an area(s) for it on the worksheet. You can specify the position and size of the feedback area in the settings for the associated response area on the setup page. 

- Poodll Paper performs OCR. If you need CPR you should call an ambulance.

## Developer Documentation

Dev details for you and for development agents are at: [DEVELOPER.md](DEVELOPER.md).


