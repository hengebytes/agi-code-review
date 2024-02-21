# TransformContextAgent

```
docker-compose exec php php bin/console agi:agent:create
```

## 1. Remove Sensitive Details

#### Access Name (model name in localai):
`gpt-4`

#### Access Key:
`sk-localai`

#### Match message (extra data):
`type = jira-issue`

#### System prompt:
```
As a security engineer, you're going to share task description with external vendor.
Carefully remove all sensitive information, including:
- URLs
- smart links
- credentials
- project name
- references
- estimates
- deployment details
- etc.
  Check result one by one to make sure all items from the list above are removed.
```

#### Sample input:
```
TASK NAME:
Adjust Title Handling in Various LocalAI Channels

TASK DESCRIPTION:
Update the logic and flow for the Title field, retaining its use despite API no longer requiring it for profile. The changes should be applied to the following:

*LIST OF TITLES* (updated on 23 January by Daniella)

||*EN*||*DE*||*ES*||*FR*||*ZH*||*JA*||*PT*||*UA*||
|Mr|Herr|Senor|Monsieur|先生|様|Senhor|Містер|
|Mrs|Frau|Senora|Madame|女士|様|Senhora|Місіс|
|Ms|Frau|Senora|Mademoiselle|女士|様|Senhora|Пані|

## Should be also implemented on Partner Sites
#### [https://www.partner-a.com/en|https://www.partner-a.com/en]
#### [https://www.partner-b.com/en|https://www.partner-b.com/en]

*ESTIMATE*

||*TASK*||*HOURS*||
|Investigation| |
|Design|0|
|FED|2.5|
|API|2|
|CMS|0|
|QA (estimated by Ivan Ternovtsii)|1|
|PM|0|
|Contingency| |
|*TOTAL*|*5.5*|

Test user credentials:
Username: t.bilane
Password: Asdfsdf234235

Note:
Issue occured for David initially, but I was able to reproduce it on my end with real profile - iternovtsii.
```

#### Sample output:
```
TASK NAME:
Adjust Title Handling in Various [non-disclosed] Channels

TASK DESCRIPTION:
Update the logic and flow for the Title field, retaining its use despite API no longer requiring it for profile. The changes should be applied to the following:

*LIST OF TITLES* (updated on 23 January by [non-disclosed])

||*EN*||*DE*||*ES*||*FR*||*ZH*||*JA*||*PT*||*UA*||
|Mr|Herr|Senor|Monsieur|先生|様|Senhor|Містер|
|Mrs|Frau|Senora|Madame|女士|様|Senhora|Місіс|
|Ms|Frau|Senora|Mademoiselle|女士|様|Senhora|Пані|

## Should be also implemented on Partner Sites
#### [non-disclosed]
#### [non-disclosed]

Test user credentials:
Username: [non-disclosed]
Password: [non-disclosed]

Note:
Issue occured for [non-disclosed] initially, but I was able to reproduce it on my end with real profile - [non-disclosed].
```

#### AI base URL:
`http://localai/v1`

## 2. Summarize Jira Issue

#### Access Name (model name):
`gpt-4`

#### Match message (extra data):
```
type = jira-issue, hasComments = Y
```

#### System prompt:
```
As a technical lead, carefully review the task description and comments one by one (splitted by ---).
Adjust task description based on relevant comments. Exclude estimates, links, etc.
If no changes are required, just leave the task description as is.
```
