import React from 'react';
import PropTypes from 'prop-types';

import Boolean from '../boolean';
import { toBool } from 'dfv/src/helpers/booleans';

import { FIELD_PROP_TYPE_SHAPE } from 'dfv/src/config/prop-types';

const BooleanGroup = ( {
	fieldConfig = {},
	setOptionValue,
	values,
} ) => {
	const {
		boolean_group: booleanGroup = [],
		htmlAttr: htmlAttributes = {},
	} = fieldConfig;

	const toggleChange = ( name ) => ( event ) => {
		console.log('handleChange', name, event.target.checked );
		setOptionValue( name, ! values[ name ] );
	};

	console.log('boolean group all values', values);

	return (
		<div className="pods-boolean-group">
			{ booleanGroup.map( ( subField ) => {
				const {
					help,
					label,
					name,
				} = subField;

				const idAttribute = !! htmlAttributes.id ? htmlAttributes.id : name;

				return (
					<div className="pods-field pods-boolean" key={ subField.name }>
						<label
							className="pods-form-ui-label pods-checkbox-pick__option__label"
							htmlFor={ idAttribute }
						>
							<input
								name={ name }
								id={ idAttribute }
								className="pods-form-ui-field-type-pick"
								type="checkbox"
								value={ 1 }
								checked={ toBool( values[ name ] ) }
								onChange={ toggleChange( name ) }
							/>
							{ label }
						</label>
					</div>
				);
			} ) }
		</div>
	);
};

BooleanGroup.propTypes = {
	fieldConfig: FIELD_PROP_TYPE_SHAPE,
	setOptionValue: PropTypes.func.isRequired,
	values: PropTypes.object,
};

export default BooleanGroup;
