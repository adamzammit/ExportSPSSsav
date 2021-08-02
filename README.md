# ExportSPSSsav
LimeSurvey plugin to allow for the export to an SPSS/PSPP sav file without the need for running any syntax (direct to an SPSS binary SAV file). Uses the https://github.com/tiamo/spss library (thank you!)

## Why use this instead of the built in SPSS export

1. Current SPSS export requires the export of an SPSS syntax and data file, requiring you to execute the syntax to produce a data file (so this saves a step)
2. This export allows you to choose which columns to include (existing one always exports all fields)

## Installation

Download the zip from the [releases](https://github.com/adamzammit/ExportSPSSsav/releases) page and extract to your plugins folder. You will also need to download [tiamo/spss](https://github.com/adamzammit/spss) and place it in the helpers/spss folder. Alternativel, you can also clone directly from git: with the tiamo/spss submodule included. Go to your plugins directory and type:
```
git clone --recurse-submodules https://github.com/adamzammit/ExportSPSSsav.git ExportSPSSsav
```

## Requirements

- LimeSurvey version 3.x, 4.x

## Configuration (LimeSurvey)

1. Visit the "Plugin manager" section in your LimeSurvey installation under "Configuration"
2. Activate the ExportSPSSsav plugin
3. You can configure the plugin to work with old versions of SPSS that don't support long strings if required

### Usage

1. Visit "Export responses" (not Export responses to SPSS) under "Responses and Statistics" of an active survey in LimeSurvey
2. A new "Export format" will appear (SPSS .sav)
3. Choose the variables you wish to export and click on "Export" - a sav file will be presented for download

Notes:

1. Choosing "Answer codes" or "Full answers" under "Responses" will have no effect, "Answer codes" are always exported and fully labelled.
2. Choosing "Export questions as" under "Headings" will have no effect - variable names are always the question code and the variable is labelled with the full question text

## Security

If you discover any security related issues, please email adam@acspri.org.au instead of using the issue tracker.

## Contributing

PR's are welcome!

## Usage

You are free to use/change/fork this code for your own products (licence is GPLv2 or greater), and I would be happy to hear how and what you are using it for!
