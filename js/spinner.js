var PdfFormsFillerSpinner = {
	spinners: 0,
	show: function()
	{
		this.spinners++;
		if(this.spinners==1)
			jQuery('.pdf-forms-filler-spinner-overlay').attr('style', 'display: block;');
	},
	hide: function()
	{
		if(this.spinners > 0)
			this.spinners--;
		if(this.spinners==0)
			jQuery('.pdf-forms-filler-spinner-overlay').attr('style', 'display: none;');
	}
}
