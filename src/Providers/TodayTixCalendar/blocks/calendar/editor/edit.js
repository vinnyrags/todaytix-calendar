import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { Disabled, PanelBody, TextControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

// ServerSideRender hits render.php, so authors preview the real calendar (live
// availability and all) right in the editor. Wrapped in <Disabled> so its links and
// month paging don't fire on the canvas, while the block itself stays selectable.
export default function Edit({ attributes, setAttributes }) {
    const { heading, intro } = attributes;
    const blockProps = useBlockProps();

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Calendar Text', 'todaytix-calendar')} initialOpen={true}>
                    <TextControl
                        label={__('Heading', 'todaytix-calendar')}
                        help={__('Optional title shown above the calendar. Leave blank to omit.', 'todaytix-calendar')}
                        value={heading}
                        onChange={(value) => setAttributes({ heading: value })}
                        __next40pxDefaultSize
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={__('Intro', 'todaytix-calendar')}
                        help={__('Optional line of text shown below the heading.', 'todaytix-calendar')}
                        value={intro}
                        onChange={(value) => setAttributes({ intro: value })}
                        __next40pxDefaultSize
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <div {...blockProps}>
                <Disabled>
                    <ServerSideRender block="todaytix/calendar" attributes={attributes} />
                </Disabled>
            </div>
        </>
    );
}
