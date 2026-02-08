import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import '../../../../app/enums/http_method.dart';
import '../../../../app/enums/monitor_type.dart';
import '../../../../app/validation/rules/between_numeric.dart';
import '../app_card.dart';

/// Shared "Basic Information" section used by both create and edit monitor views.
///
/// Contains: name, type selector, URL, HTTP method, expected status code, tags.
class MonitorBasicInfoSection extends StatelessWidget {
  final MagicFormData form;
  final MonitorType selectedType;
  final ValueChanged<MonitorType> onTypeChanged;
  final bool typeEditable;
  final List<String> tags;
  final List<SelectOption<String>> tagOptions;
  final ValueChanged<List<String>> onTagsChanged;
  final ValueChanged<List<SelectOption<String>>> onTagOptionsChanged;

  /// Optional FocusNode for expected status code input (for keyboard actions)
  final FocusNode? expectedStatusCodeFocusNode;

  const MonitorBasicInfoSection({
    super.key,
    required this.form,
    required this.selectedType,
    required this.onTypeChanged,
    this.typeEditable = true,
    required this.tags,
    required this.tagOptions,
    required this.onTagsChanged,
    required this.onTagOptionsChanged,
    this.expectedStatusCodeFocusNode,
  });

  @override
  Widget build(BuildContext context) {
    return AppCard(
      title: trans('monitor.basic_information'),
      body: WDiv(
        className: 'flex flex-col gap-4',
        children: [
          // Monitor Name
          WFormInput(
            label: trans('monitor.name'),
            hint: trans('monitor.name_hint'),
            controller: form['name'],
            labelClassName: '''
              text-gray-900 dark:text-gray-200
              mb-2 text-sm font-medium
            ''',
            className: '''
              w-full bg-white dark:bg-gray-800
              text-gray-900 dark:text-white
              rounded-lg
              border border-gray-200 dark:border-gray-700
              px-3 py-3
              text-sm
              focus:border-primary
              focus:ring-2 focus:ring-primary/20
              error:border-red-500
            ''',
            validator: FormValidator.rules([
              Required(),
              Min(3),
              Max(255),
            ], field: 'name'),
          ),

          // Monitor Type
          WDiv(
            className: 'flex flex-col gap-2',
            children: [
              WText(
                trans('monitor.type'),
                className:
                    'text-sm font-medium text-gray-900 dark:text-gray-200',
              ),
              Row(
                children: MonitorType.values.map((type) {
                  final isSelected = selectedType == type;
                  return Expanded(
                    child: Padding(
                      padding: EdgeInsets.only(
                        right: type != MonitorType.values.last ? 8 : 0,
                      ),
                      child: WAnchor(
                        onTap: typeEditable ? () => onTypeChanged(type) : null,
                        child: WDiv(
                          className:
                              '''
                            px-4 py-3 rounded-lg border-2
                            ${isSelected ? 'bg-primary/10 border-primary' : 'bg-transparent border-gray-200 dark:border-gray-700'}
                          ''',
                          child: Center(
                            child: WText(
                              type.label,
                              className:
                                  'text-sm font-medium ${isSelected ? 'text-[#009E60]' : 'text-gray-700 dark:text-gray-300'}',
                            ),
                          ),
                        ),
                      ),
                    ),
                  );
                }).toList(),
              ),
            ],
          ),

          // URL
          WFormInput(
            label: trans('monitor.url'),
            hint: selectedType == MonitorType.http
                ? trans('monitor.url_hint_http')
                : trans('monitor.url_hint_ping'),
            controller: form['url'],
            labelClassName: '''
              text-gray-900 dark:text-gray-200
              mb-2 text-sm font-medium
            ''',
            className: '''
              w-full bg-white dark:bg-gray-800
              text-gray-900 dark:text-white
              rounded-lg
              border border-gray-200 dark:border-gray-700
              px-3 py-3
              text-sm font-mono
              focus:border-primary
              focus:ring-2 focus:ring-primary/20
              error:border-red-500
            ''',
            validator: FormValidator.rules([
              Required(),
              Max(2048),
            ], field: 'url'),
          ),

          // HTTP Method (only for HTTP monitors)
          if (selectedType == MonitorType.http)
            WFormSelect<String>(
              label: trans('monitor.method'),
              value: form.get('method'),
              options: HttpMethod.selectOptions
                  .map(
                    (opt) =>
                        SelectOption(value: opt.value.value, label: opt.label),
                  )
                  .toList(),
              onChange: (value) {
                if (value != null) form.set('method', value);
              },
              labelClassName: '''
                text-gray-900 dark:text-gray-200
                mb-2 text-sm font-medium
              ''',
              className: '''
                w-full bg-white dark:bg-gray-800
                text-gray-900 dark:text-white
                rounded-lg
                border border-gray-200 dark:border-gray-700
                px-3 py-3
                text-sm
              ''',
              menuClassName: '''
                bg-white dark:bg-gray-800
                border border-gray-200 dark:border-gray-700
                rounded-xl shadow-xl
              ''',
            ),

          // Expected Status Code
          if (selectedType == MonitorType.http)
            WFormInput(
              label: trans('monitor.expected_status_code'),
              hint: trans('monitor.expected_status_code_hint'),
              controller: form['expected_status_code'],
              focusNode: expectedStatusCodeFocusNode,
              type: InputType.number,
              labelClassName: '''
                text-gray-900 dark:text-gray-200
                mb-2 text-sm font-medium
              ''',
              className: '''
                w-full bg-white dark:bg-gray-800
                text-gray-900 dark:text-white
                rounded-lg
                border border-gray-200 dark:border-gray-700
                px-3 py-3
                text-sm
                focus:border-primary
                focus:ring-2 focus:ring-primary/20
                error:border-red-500
              ''',
              validator: FormValidator.rules([
                Required(),
                BetweenNumeric(100, 599),
              ], field: 'expected_status_code'),
            ),

          // Tags
          WDiv(
            className: 'flex flex-col gap-2',
            children: [
              WText(
                trans('monitor.tags'),
                className:
                    'text-sm font-medium text-gray-900 dark:text-gray-200',
              ),
              WFormMultiSelect<String>(
                values: tags,
                options: tagOptions,
                onMultiChange: onTagsChanged,
                searchable: true,
                placeholder: trans('monitor.tags_hint'),
                onCreateOption: (query) async {
                  final newOption = SelectOption(value: query, label: query);
                  onTagOptionsChanged([...tagOptions, newOption]);
                  return newOption;
                },
                selectedChipBuilder: (context, option, onRemove) {
                  return WDiv(
                    className: '''
                      flex items-center gap-1
                      bg-blue-100 dark:bg-blue-900
                      rounded-lg px-2 py-1
                    ''',
                    children: [
                      WText(
                        '#${option.label}',
                        className: 'text-blue-800 dark:text-blue-200 text-sm',
                      ),
                      WButton(
                        onTap: onRemove,
                        className: 'p-0 hover:bg-transparent',
                        child: WIcon(
                          Icons.close,
                          className: 'text-blue-700 dark:text-blue-300 text-sm',
                        ),
                      ),
                    ],
                  );
                },
                className: '''
                  w-full px-3 py-2 rounded-lg
                  bg-white dark:bg-gray-800
                  border border-gray-200 dark:border-gray-700
                  focus:border-primary focus:ring-2 focus:ring-primary/20
                ''',
                menuClassName: '''
                  bg-white dark:bg-gray-800
                  border border-gray-200 dark:border-gray-700
                  rounded-xl shadow-xl
                ''',
              ),
            ],
          ),
        ],
      ),
    );
  }
}
